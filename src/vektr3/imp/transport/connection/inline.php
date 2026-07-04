<?php

namespace vektr3\imp\transport\connection;

use vektr3\imp\transport;

/**
 * The plain-SAPI connection (php -S, FPM): echo + flush, disconnect
 * detected by the SAPI after a write to a gone client. Requires
 * ignore_user_abort(true) so detection is ours, not a mid-echo kill.
 */
final class InlineConnection implements transport\Connection
{
    public function __construct(
        private readonly int $tick_us = 50_000,

        /** Must match the Transform passed to serve(), e.g. 'gzip'. */
        ?string $content_encoding = null,
    ) {
        \ignore_user_abort(true);

        \header('Content-Type: text/event-stream');
        \header('Cache-Control: no-cache');
        \header('X-Accel-Buffering: no');
        if ($content_encoding !== null) {
            \header("Content-Encoding: $content_encoding");
        }

        while (\ob_get_level() > 0) {
            \ob_end_clean();
        }
    }

    public function send(string $bytes): true|transport\FaultKind
    {
        echo $bytes;
        \flush();

        return \connection_aborted()
            ? transport\FaultKind::Closed
            : true;
    }

    public function alive(): bool
    {
        return !\connection_aborted();
    }

    public function wait(): void
    {
        \usleep($this->tick_us);
    }
}
