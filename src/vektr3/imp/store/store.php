<?php

namespace vektr3\imp\store;

//
// Path-addressed document like storage.
//

enum FaultKind
{
    case Missing;
    case Busy;
    case Unavailable;
    case Full;
    case Denied;
    case Corrupt;
    case BadPath;
    case Failed;
}

final readonly class Document
{
    public function __construct(
        public string $path,
        public int    $size,
        public int    $modified_unix,
        public int    $created_unix,
    ) {}
}

final readonly class Listing
{
    public function __construct(
        /**
         * @var list<Document>
         */
        public array $documents,

        /**
         * Continuation token, null when exhausted.
         */
        public ?string $cursor,
    ) {}
}

interface Backend
{
    public function get(string $path): string|FaultKind;

    public function put(string $path, string $bytes): true|FaultKind;

    public function delete(string $path): true|FaultKind;

    public function list(
        string $prefix,
        ?string $cursor = null,
        int $limit = 100,
        bool $desc = false
    ): Listing|FaultKind;
}

function path_fault(string $path, bool $as_prefix = false): ?FaultKind
{
    if ($as_prefix) {
        $path = \rtrim($path, '/');
        if ($path === '') {
            return null;
        }
    }

    if ($path === '' || \strlen($path) > 512) {
        return FaultKind::BadPath;
    }

    foreach (\explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return FaultKind::BadPath;
        }
        if (!\preg_match('/^[A-Za-z0-9._\\-]+$/', $segment)) {
            return FaultKind::BadPath;
        }
    }

    return null;
}
