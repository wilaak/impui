<?php

namespace vektr3\imp\transport\sapi;

use vektr3\imp;
use vektr3\imp\transport;

//
// The plain-SAPI adapter (php -S, FPM): the only place in the
// transport that reads superglobals or calls header()/echo. A host
// framework with its own request/response types skips this file
// entirely and calls transport\handle_control directly.
//

function emit(transport\Reply $reply): void
{
    \http_response_code($reply->status);

    foreach ($reply->headers as $name => $value) {
        \header("$name: $value");
    }

    echo $reply->body;
}

/**
 * The client's echoed version, wherever it traveled: the imp plugin
 * sends a query param and a header, bare Datastar drivers echo the
 * _impv signal.
 */
function read_version(): string
{
    if (isset($_GET['v'])) {
        return (string) $_GET['v'];
    }
    if (isset($_SERVER['HTTP_IMP_VERSION'])) {
        return (string) $_SERVER['HTTP_IMP_VERSION'];
    }

    $signals = \json_decode($_GET['datastar'] ?? '', true);

    return \is_array($signals) ? (string) ($signals['_impv'] ?? '') : '';
}

/**
 * The control endpoint for quickstarts: extract from $_GET, run,
 * emit. Anything beyond that (custom params, auth, page routing)
 * belongs in the app's own route handler calling handle_control.
 */
function metrics_endpoint(imp\Env $env): void
{
    emit(transport\handle_metrics($env, describe: isset($_GET['describe'])));
}

/**
 * One URL is the whole client protocol: a bare request polls,
 * ?actions=... dispatches, ?hold=watch|stream upgrades to the held
 * channel — but only the one the current plan offers, so the server
 * guides the client and the client cannot hold what load has taken
 * away.
 */
function control_endpoint(imp\Env $env, string $session_id, \Closure $ui): void
{
    $hold = $_GET['hold'] ?? null;

    if ($hold !== null) {
        if ((transport\plan($env)['hold'] ?? null) !== $hold) {
            emit(new transport\Reply(409));
            return;
        }

        if ($hold === 'watch') {
            transport\watch(
                new transport\connection\InlineConnection(content_encoding: $env->encoding),
                $env->bus,
                $session_id,
                transport\transform_for($env->encoding),
                $env->metrics,
            );
            return;
        }

        transport\stream($env, $session_id, $ui);
        return;
    }

    emit(transport\handle_control(
        $env,
        $session_id,
        $ui,
        client_version: read_version(),
        actions_json:   isset($_GET['actions']) ? (string) $_GET['actions'] : null,
    ));
}
