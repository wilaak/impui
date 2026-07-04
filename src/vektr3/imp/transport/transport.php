<?php

namespace vektr3\imp\transport;

use vektr3\imp\store;
use vektr3\imp\bus;
use vektr3\imp\session;
use vektr3\imp\engine;
use vektr3\imp\metrics;
use vektr3\imp\onramp;

//
// The transport seam. A Connection is one client's push channel; the
// session loop (serve) is written once against it and never knows
// whether bytes travel over inline SSE, a Swoole response, or a test
// fake. Disconnect is an interface question, so the loop is testable
// without a socket and can never spin forever on a dead client.
//

enum FaultKind
{
    /**
     * The client is gone. The normal way every connection ends.
     */
    case Closed;

    /**
     * The session is owned elsewhere right now (an overlapping poll,
     * a held stream). The client just polls again.
     */
    case Busy;

    /**
     * A failure no other case expresses. Propagate it, never match
     * on it: a branch on Failed means the case should be promoted.
     */
    case Failed;
}

interface Connection
{
    /**
     * Deliver bytes now. Closed on a dead client, and a send MUST
     * fail once the client is gone: it is the loop's liveness probe.
     */
    public function send(string $bytes): true|FaultKind;

    /**
     * Disconnect signals that arrive between sends (abort flags,
     * poll timeouts). Best effort; send() is the authority.
     */
    public function alive(): bool;

    /**
     * Yield until the next tick. The host knows how to sleep
     * (usleep inline, coroutine sleep on Swoole).
     */
    public function wait(): void;
}

/**
 * A wire transform (compression). Stateful: streaming compressors
 * carry their dictionary across chunks, so one instance serves one
 * connection, and every byte of the stream must pass through it.
 */
interface Transform
{
    public function apply(string $bytes): string;
}

const PING_S = 5;

/**
 * The session loop: attach, then drain/render/push until the client
 * disconnects, then flush and release. Returns Closed on a normal
 * disconnect. Busy attach means the previous holder has not noticed
 * its dead client yet, so we wait briefly for its next ping to fail.
 */
function serve(
    Connection    $conn,
    store\Backend $store,
    store\Locker  $locker,
    bus\Bus       $bus,
    string        $session_id,
    \Closure      $ui,
    ?Transform    $transform = null,
): FaultKind {
    $context = wait_attach($conn, $store, $locker, $session_id, $ui, $transform);
    if (!$context instanceof engine\Context) {
        return $context;
    }

    try {
        $last_ping = \time();

        while (true) {
            engine\drain_actions($bus, $context);
            engine\drain_sync($bus, $context);

            try {
                $html = engine\render_context($context);
            } catch (\Throwable $e) {
                $context->ui = fn() => print(engine\error_page($e));
                $context->sync_pending = true;
                continue;
            }

            if ($html !== null) {
                $sent = push($conn, $context, $transform, patch_output($context, $html));
                if ($sent instanceof FaultKind) {
                    return $sent;
                }
                $last_ping = \time();
            }

            if (\time() >= $last_ping + PING_S) {
                $sent = push($conn, $context, $transform, ": ping\n\n");
                if ($sent instanceof FaultKind) {
                    return $sent;
                }
                $last_ping = \time();
            }

            if (!$conn->alive()) {
                return FaultKind::Closed;
            }

            $conn->wait();
        }
    } finally {
        engine\detach($store, $locker, $context);
    }
}

/**
 * Transform, count, send. The counters live on the context so the ui
 * and introspection can read them; a null context (pre-attach bytes)
 * still transforms, because a streaming compressor must see every
 * byte, but counts nowhere.
 */
function push(Connection $conn, ?engine\Context $context, ?Transform $transform, string $bytes): true|FaultKind
{
    $wire = $transform?->apply($bytes) ?? $bytes;

    if ($context !== null) {
        $context->bytes_raw  += \strlen($bytes);
        $context->bytes_wire += \strlen($wire);
    }

    return $conn->send($wire);
}

function wait_attach(
    Connection    $conn,
    store\Backend $store,
    store\Locker  $locker,
    string        $session_id,
    \Closure      $ui,
    ?Transform    $transform = null,
): engine\Context|FaultKind {
    $deadline = \time() + 2 * PING_S;

    while (true) {
        $result = engine\attach($store, $locker, $session_id, $ui);
        if ($result instanceof engine\Context) {
            return $result;
        }
        if ($result !== session\FaultKind::Busy) {
            return FaultKind::Failed;
        }
        if (\time() > $deadline) {
            return FaultKind::Failed;
        }

        $sent = push($conn, null, $transform, ": waiting for session\n\n");
        if ($sent instanceof FaultKind) {
            return $sent;
        }
        if (!$conn->alive()) {
            return FaultKind::Closed;
        }

        $conn->wait();
    }
}

/**
 * The Notify watcher: a held channel that carries pokes, never
 * payloads. Watches the session's version and tells the client to
 * pull; rendering stays on stateless control requests. Never attaches
 * and never locks, so it cannot conflict with anything: it composes
 * with polling (a slow safety interval) and with other watchers of
 * the same session (two tabs, two devices).
 *
 * The first check always pokes (last starts empty), so a connecting
 * client pulls its initial render immediately.
 */
function watch(
    Connection       $conn,
    bus\Bus          $bus,
    string           $session_id,
    ?Transform       $transform = null,
    ?metrics\Metrics $metrics = null,
): FaultKind {
    if (!session\is_valid_id($session_id)) {
        return FaultKind::Failed;
    }

    try {
        held_gauge($metrics, 'watchers.held', +1);
        return watch_loop($conn, $bus, $session_id, $transform);
    } finally {
        held_gauge($metrics, 'watchers.held', -1);
    }
}

function watch_loop(
    Connection $conn,
    bus\Bus    $bus,
    string     $session_id,
    ?Transform $transform,
): FaultKind {
    $last      = '';
    $last_ping = \time();

    while (true) {
        $version = version($bus, $session_id);
        if ($version instanceof FaultKind) {
            return $version;
        }

        if ($version !== $last) {
            $sent = push($conn, null, $transform, poke_output());
            if ($sent instanceof FaultKind) {
                return $sent;
            }
            $last      = $version;
            $last_ping = \time();
        }

        if (\time() >= $last_ping + PING_S) {
            $sent = push($conn, null, $transform, ": ping\n\n");
            if ($sent instanceof FaultKind) {
                return $sent;
            }
            $last_ping = \time();
        }

        if (!$conn->alive()) {
            return FaultKind::Closed;
        }

        $conn->wait();
    }
}

/**
 * The session's change version: its bus heads, composed. Two small
 * reads, no lock, no attach: this is what makes an idle poll cheap.
 * The client echoes its last seen version back (a Datastar signal);
 * equality means nothing to render.
 */
function version(bus\Bus $bus, string $session_id): string|FaultKind
{
    $actions = $bus->head(engine\action_topic($session_id));
    if ($actions instanceof bus\FaultKind) {
        return FaultKind::Failed;
    }
    $sync = $bus->head(engine\sync_topic($session_id));
    if ($sync instanceof bus\FaultKind) {
        return FaultKind::Failed;
    }

    return "$actions.$sync";
}

/**
 * The control request, Poll mode's single endpoint. With actions:
 * publish them, then render and answer with the patch, so feedback is
 * immediate. Without: a poll, answered from the version check alone
 * when nothing changed. Busy means someone else owns the session (a
 * held stream, an overlapping control): they will deliver, we 204.
 *
 * '' means up to date. The patch carries the new version as a signal,
 * so the next poll's cheap check compares against it.
 */
function control(
    store\Backend $store,
    store\Locker  $locker,
    bus\Bus       $bus,
    string        $session_id,
    \Closure      $ui,
    string        $client_version = '',
    ?string       $actions_json = null,
): string|FaultKind {
    if (!session\is_valid_id($session_id)) {
        return FaultKind::Failed;
    }

    if ($actions_json !== null) {
        handle_actions($bus, $session_id, $actions_json);
    } elseif ($client_version !== '' && version($bus, $session_id) === $client_version) {
        return '';
    }

    $context = engine\attach($store, $locker, $session_id, $ui);
    if ($context === session\FaultKind::Busy) {
        return FaultKind::Busy;
    }
    if (!$context instanceof engine\Context) {
        return FaultKind::Failed;
    }

    try {
        engine\drain_actions($bus, $context);

        try {
            $html = engine\render_context($context);
        } catch (\Throwable $e) {
            $html = engine\error_page($e);
        }

        if ($html === null) {
            return '';
        }

        $new_version = version($bus, $session_id);

        return patch_output($context, $html)
            . version_signal(\is_string($new_version) ? $new_version : '');
    } finally {
        engine\detach($store, $locker, $context);
    }
}

/**
 * The transport plan: the server's standing orders to the client,
 * sent with every control reply. The client obeys and keeps obeying:
 * which channel to hold (if any), how often to pull, all decided
 * here, per request, from the deployment's mode ladder and the
 * current load rung. Demotion under load is just the next reply
 * carrying a humbler plan.
 *
 * @return array{mode: string, interval_ms: int, hold?: string}
 */
function plan(\vektr3\imp\Env $env): array
{
    $rung = (new onramp\StoreLoadState($env->store))->rung();

    // Under load, everyone degrades to polling, slower the worse it
    // gets: connections are reclaimed and latency absorbs the strain.
    if ($rung->value >= onramp\Rung::Elevated->value) {
        return ['mode' => 'poll', 'interval_ms' => match ($rung) {
            onramp\Rung::Elevated => 2000,
            onramp\Rung::High     => 5000,
            default               => 15000,
        }];
    }

    return match ($env->modes[0] ?? \vektr3\imp\Mode::Poll) {
        \vektr3\imp\Mode::Push   => ['mode' => 'push',   'interval_ms' => 5000, 'hold' => 'stream'],
        \vektr3\imp\Mode::Notify => ['mode' => 'notify', 'interval_ms' => 5000, 'hold' => 'watch'],
        \vektr3\imp\Mode::Poll   => ['mode' => 'poll',   'interval_ms' => 1000],
    };
}

/**
 * A host-agnostic response: plain data the host adapter maps onto its
 * own primitives (header()/echo on plain SAPI, $response on Swoole).
 * The core never touches a SAPI function.
 */
final readonly class Reply
{
    public function __construct(
        public int $status,

        /** @var array<string, string> */
        public array $headers = [],

        public string $body = '',
    ) {}
}

/**
 * The control request as pure data-in, Reply-out. The host extracts
 * $session_id, $client_version and $actions_json with its own router
 * and request API; how they traveled is its business.
 */
function handle_control(
    \vektr3\imp\Env $env,
    string          $session_id,
    \Closure        $ui,
    string          $client_version = '',
    ?string         $actions_json = null,
): Reply {
    $started = \hrtime(true);

    $patch = control(
        $env->store,
        $env->locker,
        $env->bus,
        $session_id,
        $ui,
        $client_version,
        $actions_json,
    );

    $env->metrics->count(match (true) {
        $patch === FaultKind::Failed => 'control.bad',
        $patch === FaultKind::Busy   => 'control.busy',
        $patch instanceof FaultKind  => 'control.failed',
        $patch === ''                => 'control.fresh',
        default                      => 'control.patch',
    });
    if (\is_string($patch) && $patch !== '') {
        $env->metrics->count('control.bytes', \strlen($patch));
    }
    $env->metrics->count('control.ms', (int) ((\hrtime(true) - $started) / 1_000_000));
    $env->metrics->flush();

    if ($patch === FaultKind::Failed) {
        return new Reply(400);
    }

    // Every non-error reply carries the standing orders: the current
    // version (so headers alone close the cheap-poll loop) and the
    // plan the client must obey. Demotion needs no special path; the
    // next reply simply says something humbler.
    $version = version($env->bus, $session_id);
    $headers = [
        'imp-version' => \is_string($version) ? $version : '',
        'imp-plan'    => \json_encode(plan($env)),
    ];

    if ($patch instanceof FaultKind || $patch === '') {
        return new Reply(204, $headers);
    }

    return new Reply(200, $headers + [
        'Content-Type'  => 'text/event-stream',
        'Cache-Control' => 'no-cache',
    ], $patch);
}

/**
 * The metrics read endpoint: flush our own numbers, aggregate all
 * live shards, answer with the aggregate. Aggregating on demand means
 * it works with no background worker running (dev); with one, this
 * just refreshes a little earlier than the next tick would.
 */
function handle_metrics(\vektr3\imp\Env $env, bool $describe = false): Reply
{
    $env->metrics->flush();

    $aggregated = metrics\aggregate($env->store, $env->locker, \time());
    if ($aggregated !== true) {
        return new Reply(503);
    }

    $agg = metrics\read_aggregate($env->store);
    if (!\is_array($agg)) {
        return new Reply(503);
    }

    if ($describe) {
        $agg['catalog'] = metrics\CATALOG;
    }

    return new Reply(200, ['Content-Type' => 'application/json'], \json_encode($agg, \JSON_PRETTY_PRINT));
}

/**
 * A fresh Transform for the Env's configured encoding. Per connection,
 * never shared: streaming compressors are stateful.
 */
function transform_for(?string $encoding): ?Transform
{
    return match ($encoding) {
        'gzip' => new transform\GzipTransform(),
        'zstd' => new transform\ZstdTransform(),
        null   => null,
    };
}

/**
 * The inline SSE endpoint over an Env: the one-liner for plain-SAPI
 * routes. Explicit wiring stays available via serve().
 */
function stream(\vektr3\imp\Env $env, string $session_id, \Closure $ui): FaultKind
{
    try {
        held_gauge($env->metrics, 'streams.held', +1);

        return serve(
            new connection\InlineConnection(content_encoding: $env->encoding),
            $env->store,
            $env->locker,
            $env->bus,
            $session_id,
            $ui,
            transform_for($env->encoding),
        );
    } finally {
        held_gauge($env->metrics, 'streams.held', -1);
    }
}

/**
 * Track this process's held connections as a live gauge, flushed
 * immediately: held connections are exactly the number the ladder
 * and the census must see promptly.
 */
function held_gauge(?metrics\Metrics $metrics, string $name, int $delta): void
{
    static $held = [];

    if ($metrics === null) {
        return;
    }

    $held[$name] = \max(0, ($held[$name] ?? 0) + $delta);
    $metrics->gauge($name, (float) $held[$name]);
    $metrics->flush();
}

/**
 * The action ingress, callable from any worker. $actions_json is the
 * client's list: [{"name": "...", "params": ...}, ...].
 */
function handle_actions(bus\Bus $bus, string $session_id, string $actions_json): void
{
    if (!session\is_valid_id($session_id)) {
        return;
    }

    $actions = \json_decode($actions_json, true);
    if (!\is_array($actions)) {
        return;
    }

    foreach ($actions as $action) {
        if (\is_array($action) && \is_string($action['name'] ?? null)) {
            engine\dispatch_action($bus, $session_id, $action['name'], $action['params'] ?? true);
        }
    }
}
