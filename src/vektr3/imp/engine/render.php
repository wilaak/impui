<?php

namespace vektr3\imp\engine;

const NS_PER_MS = 1_000_000;

/**
 * Render the context if a sync is pending, returning the HTML or
 * null when there is nothing to send.
 *
 * Cascading renders (an action during render requesting another
 * sync) are bounded at two attempts; past that the extra sync waits
 * for the next tick instead of looping.
 */
function render_context(Context $context): ?string
{
    if (!$context->sync_pending) {
        return null;
    }

    $start = \hrtime(true);

    // Throttle so a hot loop cannot flood the client.
    $throttle_ms = $context->client_hidden ? 5000 : 33;
    if (($start - $context->last_sync_ns) / NS_PER_MS < $throttle_ms) {
        return null;
    }

    Context::$current = $context;
    $html = null;

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $context->sync_pending = false;

        \ob_start();
        try {
            ($context->ui)();
        } catch (\Throwable $e) {
            \ob_end_clean();
            Context::$current = null;
            throw $e;
        }

        if ($context->sync_pending && $attempt < 2) {
            \ob_end_clean();
            continue;
        }

        $html = \ob_get_clean();
        break;
    }

    Context::$current = null;

    complete_render($context);

    $context->last_sync_ns = $start;
    $context->sync_count++;
    $context->render_ms_total += (\hrtime(true) - $start) / NS_PER_MS;

    return $html;
}

/**
 * Sweep state objects not requested this render, so abandoned UI
 * state cannot accumulate for the life of the session.
 */
function complete_render(Context $context): void
{
    foreach ($context->state_map as $id => $_) {
        if (($context->state_used_map[$id] ?? -1) === $context->render_count) {
            continue;
        }
        unset($context->state_map[$id], $context->state_used_map[$id]);
    }

    $context->action_map = [];
    $context->render_count++;
}
