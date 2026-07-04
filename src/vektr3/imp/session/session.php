<?php

namespace vektr3\imp\session;

use vektr3\imp\store;

//
// Per-session state over the store, mutated only under the session's
// lock. acquire() -> mutate -> save() -> release(), identical under
// FPM and resident workers.
//

enum FaultKind
{
    /**
     * Not a UUID v4. Client-supplied, so rejection is routine.
     */
    case BadId;

    /**
     * The session is being served by another request right now.
     */
    case Busy;

    /**
     * Stored state failed to decode. Caller decides: usually reset()
     * for a fresh start rather than failing the client forever.
     */
    case Corrupt;

    /**
     * The backing store failed. Retryable.
     */
    case Unavailable;

    /**
     * A failure no other case expresses. Propagate it, never match
     * on it: a branch on Failed means the case should be promoted.
     */
    case Failed;
}

final class Session
{
    public function __construct(
        public readonly string     $id,
        public readonly store\Lock $lock,

        /** @var array<class-string, object> */
        public array $state_map = [],

        /** @var array<string, int|float|string|bool|null> Runtime bookkeeping (bus cursors), persisted with the state. */
        public array $meta = [],
    ) {}
}

function is_valid_id(string $id): bool
{
    return (bool) \preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
        $id,
    );
}

/**
 * Lock the session and load its state. A session that has never
 * saved is simply empty: creation is lazy, an unestablished session
 * costs only its token.
 */
function acquire(store\Backend $store, store\Locker $locker, string $id): Session|FaultKind
{
    if (!is_valid_id($id)) {
        return FaultKind::BadId;
    }

    $lock = $locker->lock("session/$id");
    if ($lock instanceof store\FaultKind) {
        return fault($lock);
    }

    $bytes = $store->get("session/$id/state");
    if ($bytes === store\FaultKind::Missing) {
        return new Session($id, $lock);
    }
    if ($bytes instanceof store\FaultKind) {
        $locker->unlock($lock);
        return fault($bytes);
    }

    $doc = @\unserialize($bytes);
    if (!\is_array($doc) || !\is_array($doc['state'] ?? null) || !\is_array($doc['meta'] ?? null)) {
        $locker->unlock($lock);
        return FaultKind::Corrupt;
    }

    return new Session($id, $lock, $doc['state'], $doc['meta']);
}

/**
 * Persist the state. The session stays locked: save early and often,
 * release once.
 */
function save(store\Backend $store, Session $session): true|FaultKind
{
    $put = $store->put("session/{$session->id}/state", \serialize([
        'state' => $session->state_map,
        'meta'  => $session->meta,
    ]));

    return $put instanceof store\FaultKind ? fault($put) : true;
}

function release(store\Locker $locker, Session $session): void
{
    $locker->unlock($session->lock);
}

/**
 * Discard everything stored for the session, keeping the lock. The
 * recovery path for Corrupt, and the deletion hint for logout.
 */
function reset(store\Backend $store, Session $session): true|FaultKind
{
    $deleted = store\delete_prefix($store, "session/{$session->id}/");
    if ($deleted instanceof store\FaultKind) {
        return fault($deleted);
    }

    $session->state_map = [];
    return true;
}

function fault(store\FaultKind $kind): FaultKind
{
    return match ($kind) {
        store\FaultKind::Busy        => FaultKind::Busy,
        store\FaultKind::Corrupt     => FaultKind::Corrupt,
        store\FaultKind::Unavailable => FaultKind::Unavailable,
        default                      => FaultKind::Failed,
    };
}
