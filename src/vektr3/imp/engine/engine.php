<?php

namespace vektr3\imp\engine;

use vektr3\imp\bus;

//
// The per-worker tick. Each worker ticks only the contexts it has
// attached (and therefore locks): drain actions off the bus, render
// what is dirty, hand the patches to the transport. No global state
// beyond the worker's own attachment table.
//

final class Engine
{
    /** @var array<string, Context> Attached contexts by session id, this worker only. */
    public static array $contexts = [];

    public static bool $running = false;
}

/**
 * Returns rendered patches by session id for the transport to push.
 * A context whose render throws gets its error surfaced as the next
 * ui until the developer fixes it; the session itself survives.
 */
function tick(bus\Bus $bus): array
{
    $patches = [];

    foreach (Engine::$contexts as $session_id => $context) {
        drain_actions($bus, $context);

        try {
            $html = render_context($context);
        } catch (\Throwable $e) {
            $context->ui = fn() => print(error_page($e));
            $context->sync_pending = true;
            continue;
        }

        if ($html !== null) {
            $patches[$session_id] = $html;
        }
    }

    return $patches;
}
