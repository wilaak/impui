<?php

namespace vektr3\imp\engine;

/**
 * Rendered in place of the ui after it throws, until the code is
 * fixed. The session itself survives: state is intact, the next
 * successful render resumes normally.
 */
function error_page(\Throwable $e): string
{
    $detail = \htmlspecialchars((string) $e, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

    return <<<HTML
        <main id="app" style="font-family: monospace; padding: 2rem;">
            <h2>Render failed</h2>
            <pre style="white-space: pre-wrap; background: #1113; padding: 1rem;">$detail</pre>
        </main>
        HTML;
}
