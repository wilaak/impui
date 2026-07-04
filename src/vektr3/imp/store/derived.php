<?php

namespace vektr3\imp\store;

interface Movable
{
    public function move(string $from, string $to): true|FaultKind;
}

interface PrefixDeletable
{
    public function deletePrefix(string $prefix): true|FaultKind;
}

function stat(Backend $backend, string $path): Document|FaultKind
{
    $listing = $backend->list($path, null, 1);
    if ($listing instanceof FaultKind) {
        return $listing;
    }

    $document = $listing->documents[0] ?? null;

    return $document !== null && $document->path === $path
        ? $document
        : FaultKind::Missing;
}

function move(Backend $backend, string $from, string $to): true|FaultKind
{
    if ($backend instanceof Movable) {
        return $backend->move($from, $to);
    }

    $bytes = $backend->get($from);

    if ($bytes === FaultKind::Missing) {
        return true;
    }
    if ($bytes instanceof FaultKind) {
        return $bytes;
    }

    $put = $backend->put($to, $bytes);
    if ($put instanceof FaultKind) {
        return $put;
    }

    return $backend->delete($from);
}

function delete_prefix(Backend $backend, string $prefix): true|FaultKind
{
    if ($backend instanceof PrefixDeletable) {
        return $backend->deletePrefix($prefix);
    }

    do {
        $listing = $backend->list($prefix, null, 500);
        if ($listing instanceof FaultKind) {
            return $listing;
        }

        foreach ($listing->documents as $document) {
            $deleted = $backend->delete($document->path);
            if ($deleted instanceof FaultKind) {
                return $deleted;
            }
        }
    } while ($listing->cursor !== null);

    return true;
}
