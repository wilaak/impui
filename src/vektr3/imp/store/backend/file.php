<?php

namespace vektr3\imp\store\backend;

use vektr3\imp\store;

final readonly class FileStore implements
    store\Backend,
    store\Movable
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = \rtrim($root, '/');
    }

    public function get(string $path): string|store\FaultKind
    {
        $fault = store\path_fault($path);
        if ($fault !== null) {
            return $fault;
        }

        $file  = $this->file($path);
        $bytes = @\file_get_contents($file);

        return match (true) {
            $bytes !== false     => $bytes,
            !\file_exists($file) => store\FaultKind::Missing,
            default              => store\FaultKind::Unavailable,
        };
    }

    public function put(string $path, string $bytes): true|store\FaultKind
    {
        $fault = store\path_fault($path);
        if ($fault !== null) {
            return $fault;
        }

        $file = $this->file($path);
        $dir  = \dirname($file);

        if (!\is_dir($dir) && !@\mkdir($dir, 0770, true) && !\is_dir($dir)) {
            return store\FaultKind::Denied;
        }

        $tmp = $dir . '/.tmp.' . \bin2hex(\random_bytes(8));

        if (@\file_put_contents($tmp, $bytes) !== \strlen($bytes)) {
            @\unlink($tmp);
            return $this->writeFault($dir, \strlen($bytes));
        }

        if (!@\rename($tmp, $file)) {
            @\unlink($tmp);
            return store\FaultKind::Unavailable;
        }

        return true;
    }

    public function delete(string $path): true|store\FaultKind
    {
        $fault = store\path_fault($path);
        if ($fault !== null) {
            return $fault;
        }

        @\unlink($this->file($path));
        return true;
    }

    public function list(string $prefix, ?string $cursor = null, int $limit = 100, bool $desc = false): store\Listing|store\FaultKind
    {
        $fault = store\path_fault($prefix, as_prefix: true);
        if ($fault !== null) {
            return $fault;
        }

        // Walk, filter, sort: simple over clever. The default backend
        // serves modest namespaces; past that, swap in an indexed one.
        $slash = \strrpos($prefix, '/');
        $dir   = $this->root . ($slash === false ? '' : '/' . \substr($prefix, 0, $slash));

        $paths = [];
        $this->walk($dir, $paths);

        $paths = \array_filter($paths, fn(string $p) => \str_starts_with($p, $prefix));
        \sort($paths, \SORT_STRING);

        if ($desc) {
            $paths = \array_reverse($paths);
        }

        $documents = [];
        foreach ($paths as $path) {
            if ($cursor !== null && ($desc ? $path >= $cursor : $path <= $cursor)) {
                continue;
            }

            $document = $this->document($path);
            if ($document === null) {
                continue;
            }

            $documents[] = $document;
            if (\count($documents) === $limit) {
                return new store\Listing($documents, $path);
            }
        }

        return new store\Listing($documents, null);
    }

    public function move(string $from, string $to): true|store\FaultKind
    {
        $fault = store\path_fault($from) ?? store\path_fault($to);
        if ($fault !== null) {
            return $fault;
        }

        $dir = \dirname($this->file($to));
        if (!\is_dir($dir) && !@\mkdir($dir, 0770, true) && !\is_dir($dir)) {
            return store\FaultKind::Denied;
        }

        if (@\rename($this->file($from), $this->file($to))) {
            return true;
        }

        // Missing source succeeds: idempotent under retry.
        return \file_exists($this->file($from))
            ? store\FaultKind::Unavailable
            : true;
    }

    private function file(string $path): string
    {
        return $this->root . '/' . $path;
    }

    private function document(string $path): ?store\Document
    {
        $stat = @\stat($this->file($path));
        if ($stat === false) {
            return null;   // vanished mid-walk
        }

        return new store\Document(
            path:          $path,
            size:          $stat['size'],
            modified_unix: $stat['mtime'],
            created_unix:  $stat['ctime'],
        );
    }

    private function writeFault(string $dir, int $needed): store\FaultKind
    {
        $free = @\disk_free_space($dir);

        return $free !== false && $free < $needed + 4096
            ? store\FaultKind::Full
            : store\FaultKind::Unavailable;
    }

    /**
     * @param list<string> $out
     */
    private function walk(string $dir, array &$out): void
    {
        $names = @\scandir($dir);
        if ($names === false) {
            return;
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || \str_starts_with($name, '.tmp.')) {
                continue;
            }

            $full = $dir . '/' . $name;
            if (\is_dir($full)) {
                $this->walk($full, $out);
            } else {
                $out[] = \substr($full, \strlen($this->root) + 1);
            }
        }
    }
}
