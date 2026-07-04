<?php

namespace vektr3\imp\engine;

use vektr3\imp\session;
use vektr3\imp\store;

//
// One resident UI context per attached session. The worker that
// attaches holds the session lock for the whole attachment, so it is
// the single writer by construction: actions from other workers
// arrive over the bus (actions.php), never by touching this object.
//

final class Context
{
    /**
     * The context being rendered right now. A static slot instead of
     * a parameter threaded through every ui function: renders are
     * strictly one at a time per worker.
     */
    public static ?Context $current = null;

    public function __construct(
        public readonly session\Session $session,
        public \Closure $ui,
    ) {}

    /** @var array<string, object> */
    public array $state_map = [];

    /** @var array<string, int> */
    public array $state_used_map = [];

    /** @var array<string, mixed> */
    public array $action_map = [];

    /** @var list<string> */
    public array $script_list = [];

    public bool    $vt_pending  = false;
    public ?string $vt_selector = null;

    public bool $sync_pending = true;
    public int  $sync_count   = 0;
    public int  $last_sync_ns = 0;

    public int   $render_count    = 0;
    public float $render_ms_total = 0.0;

    public bool $client_hidden = false;

    public int $bytes_raw  = 0;
    public int $bytes_wire = 0;

    /**
     * Bus feed position for the session's action topic. Null until
     * the first drain, which adopts the topic's current head: actions
     * published before this attachment are void, never replayed.
     */
    public ?int $action_cursor = null;

    /** Sync-channel position; a moved head schedules a render. */
    public ?int $sync_cursor = null;
}

/**
 * Lock the session, load its state, become its single writer. Busy
 * means another worker owns it (or a previous attachment has not let
 * go yet); the transport decides to retry or shed.
 */
function attach(store\Backend $store, store\Locker $locker, string $id, \Closure $ui): Context|session\FaultKind
{
    $s = session\acquire($store, $locker, $id);
    if ($s instanceof session\FaultKind) {
        return $s;
    }

    $context = new Context($s, $ui);
    $context->state_map = $s->state_map;

    $cursor = $s->meta['action_cursor'] ?? null;
    $context->action_cursor = \is_int($cursor) ? $cursor : null;

    return $context;
}

/**
 * Flush state and give up ownership. After this the context must not
 * be used: the next attach (any worker) picks up where this left off.
 */
function detach(store\Backend $store, store\Locker $locker, Context $context): true|session\FaultKind
{
    $context->session->state_map = $context->state_map;
    if ($context->action_cursor !== null) {
        $context->session->meta['action_cursor'] = $context->action_cursor;
    }

    $saved = session\save($store, $context->session);
    session\release($locker, $context->session);

    if (Context::$current === $context) {
        Context::$current = null;
    }

    return $saved;
}
