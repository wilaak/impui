<?php

namespace vektr3\imp;

use vektr3\imp\engine;
use vektr3\imp\store;
use vektr3\imp\bus;
use vektr3\imp\metrics;

use function htmlspecialchars;
use function array_key_exists;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * How changes reach the client: the two axes (held channel or not,
 * payload or poke) named as rungs of a degradation ladder.
 *
 * Push:   held channel carries the rendered payload. Lowest latency,
 *         holds the session and a worker. Resident runtimes.
 * Notify: held channel carries only a poke ("pull now"); rendering
 *         stays on stateless control requests. The watcher never
 *         locks the session, so it composes with everything.
 * Poll:   no channel; the client pulls on an interval. The floor:
 *         correct on a single-worker dev server and FPM pools.
 *
 * A deployment lists what it offers, preferred first; the runtime may
 * demote a client down the list under load, never up without
 * re-admission.
 */
enum Mode
{
    case Push;
    case Notify;
    case Poll;
}

/**
 * The composition root: every seam, wired once, explicitly. Build one
 * per process in a shared env.php; a web request, a background
 * worker, and a CLI tool differ only in which loop they run over the
 * same Env, never in wiring.
 */
final readonly class Env
{
    public function __construct(
        public store\Backend $store,
        public store\Locker  $locker,
        public bus\Bus       $bus,

        /** @var list<Mode> Offered modes, preferred first: the degradation ladder. */
        public array $modes = [Mode::Poll],

        public metrics\Metrics $metrics = new metrics\NullMetrics(),

        public string $base_path = '',

        /** Wire encoding ('gzip', 'zstd') or null for none. */
        public ?string $encoding = null,
    ) {}
}

/**
 * The zero-services preset: everything file-backed under one
 * directory. The starting point for development and the correct
 * default for a single box, FPM or resident.
 */
/**
 * @param list<Mode> $modes
 */
function file_env(string $dir, array $modes = [Mode::Poll], string $base_path = '', ?string $encoding = null): Env
{
    $raw = new store\backend\FileStore("$dir/docs");

    // Metrics write through the raw backend on purpose: observing
    // your own flush would keep the shard dirty forever.
    $m = new metrics\StoreMetrics($raw);

    $backend = new metrics\MeteredBackend($raw, $m);
    $locker  = new metrics\MeteredLocker(new store\locker\FlockLocker("$dir/locks"), $m);

    return new Env(
        store:     $backend,
        locker:    $locker,
        bus:       new bus\StoreBus($backend, $locker),
        modes:     $modes,
        metrics:   $m,
        base_path: $base_path,
        encoding:  $encoding,
    );
}

/**
 * Escape a string for HTML and HTML attributes.
 * 
 * WARNING: This is not a general-purpose HTML sanitizer!
 * It only escapes special characters.
 * 
 * Generally you should have some sort of context aware
 * sanitizer or fuzzy matcher for user input.
 */
function esc(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Create a persistent state object for the session.
 * 
 * The state object is automatically cleaned up if not requested at least once.
 *
 * @template T of object
 * @param class-string<T> $class_name The class name for the state object
 * @return T
 */
function state(string $class_name): object
{
    $ctx = engine\Context::$current;
    $id = $class_name;
    $ctx->state_used_map[$id] = $ctx->render_count;
    if (!isset($ctx->state_map[$id])) {
        $ctx->state_map[$id] = new $class_name();
    }
    return $ctx->state_map[$id];
}

/**
 * Returns true if the action was triggered this render.
 */
function action(string $action_name): bool
{
    return array_key_exists($action_name, engine\Context::$current->action_map);
}

/**
 * Returns the parameters for the action, or null if it was not triggered.
 */
function params(string $action_name): mixed
{
    $ctx = engine\Context::$current;
    if (!array_key_exists($action_name, $ctx->action_map)) {
        return null;
    }
    return $ctx->action_map[$action_name];
}

/**
 * Invalidate the current render and schedule a new one.
 * 
 * The next tick will render the current context again, and send the new
 * HTML to the client.
 */
function repaint(): void
{
    engine\Context::$current->sync_pending = true;
}

/**
 * Execute JavaScript on the client on the next patch.
 */
function script(string $js): void
{
    engine\Context::$current->script_list[] = $js;
}

/**
 * Request a view transition on the next patch. If a selector is provided,
 * it will be used to scope the transition.
 * 
 * Note: The view transition API is experimental and not fully supported in all browsers.
 * 
 * @link https://developer.mozilla.org/en-US/docs/Web/API/View_Transitions_API
 */
function view_transition(?string $selector = null): void
{
    $ctx = engine\Context::$current;
    $ctx->vt_pending = true;
    if ($selector !== null) {
        $ctx->vt_selector = $selector;
    }
}