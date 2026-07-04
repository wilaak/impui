<?php

namespace vektr3\imp\transport\transform;

use vektr3\imp\transport;

/**
 * Streaming gzip over ext-zlib, available everywhere. SYNC_FLUSH per
 * chunk so every send is immediately decodable by the client, which
 * SSE requires: the stream must never sit on buffered bytes.
 *
 * Pair with a "Content-Encoding: gzip" response header.
 */
final class GzipTransform implements transport\Transform
{
    private readonly \DeflateContext $deflate;

    public function __construct(int $level = 6)
    {
        $this->deflate = \deflate_init(\ZLIB_ENCODING_GZIP, ['level' => $level]);
    }

    public function apply(string $bytes): string
    {
        return \deflate_add($this->deflate, $bytes, \ZLIB_SYNC_FLUSH);
    }
}
