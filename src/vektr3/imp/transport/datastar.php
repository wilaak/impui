<?php

namespace vektr3\imp\transport;

use vektr3\imp\engine;
use starfederation\datastar;

/**
 * Format a rendered patch as SSE wire bytes, consuming the context's
 * queued scripts and view-transition request. Pure formatting: the
 * caller decides how the bytes reach the client.
 */
function patch_output(engine\Context $context, string $html): string
{
    $out = (new datastar\events\PatchElements($html, [
        'useViewTransition'      => $context->vt_pending,
        'viewTransitionSelector' => $context->vt_selector ?? '',
    ]))->getOutput();

    $context->vt_pending  = false;
    $context->vt_selector = null;

    foreach ($context->script_list as $js) {
        $out .= (new datastar\events\ExecuteScript($js))->getOutput();
    }
    $context->script_list = [];

    return $out;
}

/**
 * The session version as a signal patch: the client stores it and
 * echoes it on every request, closing the cheap-poll loop.
 */
function version_signal(string $version): string
{
    return (new datastar\events\PatchSignals(['_impv' => $version]))->getOutput();
}

/**
 * The poke: tells the client to pull now via a stateless control
 * request. Carries no payload on purpose; the driver listens for the
 * imp-pull event and issues the @get.
 */
function poke_output(): string
{
    return (new datastar\events\ExecuteScript(
        "window.dispatchEvent(new Event('imp-pull'))",
    ))->getOutput();
}
