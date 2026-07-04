<?php

namespace vektr3\imp\transport\transform;

use vektr3\imp\transport;

/**
 * Streaming zstd over ext-zstd: better ratio and cheaper CPU than
 * gzip, but the extension is optional, so wiring chooses it only
 * when available (see supported()).
 *
 * Pair with a "Content-Encoding: zstd" response header.
 */
final class ZstdTransform implements transport\Transform
{
    private readonly mixed $context;

    public static function supported(): bool
    {
        return \function_exists('zstd_compress_init');
    }

    public function __construct(int $level = 12)
    {
        if (!self::supported()) {
            throw new \RuntimeException('ext-zstd is not loaded');
        }
        $this->context = \zstd_compress_init($level);
    }

    public function apply(string $bytes): string
    {
        return \zstd_compress_add($this->context, $bytes);
    }
}
