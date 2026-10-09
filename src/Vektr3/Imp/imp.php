<?php

namespace Vektr3\Imp;

/**
 * Escape a string for HTML.
 * 
 * NOTE: This is not a general-purpose HTML sanitizer, it only escapes special characters.
 */
function esc(string $string): string
{
    return \htmlspecialchars($string, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
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
    $session = HostSession::$current_session;
    $session->state_used[$class_name] = true;
    if (!isset($session->state_map[$class_name])) {
        $session->state_map[$class_name] = new $class_name();
    }
    return $session->state_map[$class_name];
}

/**
 * Returns true if the action was triggered this render.
 */
function action(string $action_name): bool
{
    return HostSession::$current_session->action_map[$action_name] ?? false;
}

/**
 * NOTE: From client; untrusted input.
 */
function signals(?string $path = null): mixed
{
    $value = HostSession::$current_session->signal_map;
    if ($path === null) {
        return $value;
    }
    foreach (\explode('.', $path) as $key) {
        if (!\is_array($value) || !\array_key_exists($key, $value)) {
            return null;
        }
        $value = $value[$key];
    }
    return $value;
}

/**
 * Schedule a render pass so state settles. (double-pass)
 */
function repaint(): void
{
    HostSession::$current_session->sync_pending = true;
}

/**
 * Execute JavaScript on the client on the next patch.
 */
function script(string $js): void
{
    HostSession::$current_session->script_list[] = $js;
}

/**
 * Request a view transition on this render's patch.
 * 
 * NOTE: The view transition API is experimental and not fully supported in all browsers.
 * 
 * @link https://developer.mozilla.org/en-US/docs/Web/API/View_Transitions_API
 */
function view_transition(?string $selector = null): void
{
    $session = HostSession::$current_session;
    $session->vt_pending = true;
    if ($selector !== null) {
        $session->vt_selector = $selector;
    }
}

class HostSession
{
    /**
     * The session in the current render pass.
     * 
     * WARNING: Intended to be used in a tick loop, watch out for async re-entry!
     */
    static ?HostSession $current_session = null;

    function __construct(
        public \Closure $ui_callback,
        /**
         * @var array<class-string, object> State objects in use, you may seralize this.
         */
        public array $state_map = [],
    ) {}

    /**
     * @var array<string, bool> Actions for the next render, by name.
     */
    public array $action_map = [];

    /**
     * @var array<string, mixed> Signals for the next render, sent with the latest action.
     */
    public array $signal_map = [];

    /**
     * @var array<string, true> State requested by the last render.
     */
    public array $state_used = [];

    /** @var list<string> */
    public array $script_list = [];

    public bool    $vt_pending = false;
    public ?string $vt_selector = null;

    public bool $sync_pending = true;
}

readonly class HostPatch
{
    function __construct(
        public string  $html,
        public bool    $view_transition = false,
        public ?string $selector = null,
    ) {}
}

function host_dispatch(HostSession $session, string $name, string $signals = ''): void
{
    $session->action_map[$name] = true;
    $session->sync_pending = true;

    $signals = $signals !== '' ? \json_decode($signals, true) : [];
    $session->signal_map = \is_array($signals) ? $signals : [];
}

function host_wake(HostSession $session): void
{
    $session->sync_pending = true;
}

function host_tick(HostSession $session): ?HostPatch
{
    if (!$session->sync_pending) {
        return null;
    }

    HostSession::$current_session = $session;
    try {
        for ($attempt = 1;; $attempt++) {
            $session->sync_pending = false;
            $session->state_used   = [];

            \ob_start();
            try {
                ($session->ui_callback)();
            } finally {
                $html = (string) \ob_get_clean();
            }

            $session->action_map = [];

            if (!$session->sync_pending || $attempt === 2) {
                break;
            }
        }
    } finally {
        HostSession::$current_session = null;
        $session->action_map = [];
        $session->signal_map = [];
    }

    // Sweep the unused state away
    $session->state_map = \array_intersect_key($session->state_map, $session->state_used);

    foreach ($session->script_list as $js) {
        $html .= '<script data-effect="el.remove()">' . $js . '</script>';
    }

    $patch = new HostPatch($html, $session->vt_pending, $session->vt_selector);

    $session->vt_pending  = false;
    $session->vt_selector = null;
    $session->script_list = [];

    return $patch;
}
