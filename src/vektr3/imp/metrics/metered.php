<?php

namespace vektr3\imp\metrics;

use vektr3\imp\store;

//
// Observability as wiring, not coupling: subsystems never see the
// metrics module; the composition root wraps the bottom seams in
// these decorators and everything built on them (bus, session,
// warden, engine) is observed through its storage and locking
// behavior for free.
//

/**
 * What the core emits, name by name. Served by the metrics endpoint
 * so the system documents itself; deployments merge their own
 * entries. Dynamic suffixes are documented as patterns.
 */
const CATALOG = [
    'control.patch'  => 'control requests answered with a rendered patch',
    'control.fresh'  => 'control requests answered 204: version matched, nothing rendered',
    'control.busy'   => 'control requests that found the session owned elsewhere',
    'control.bad'    => 'control requests with an invalid session id',
    'control.failed' => 'control requests that failed for any other reason',
    'control.bytes'  => 'patch bytes produced by control requests',
    'control.ms'     => 'milliseconds spent serving control requests, total',
    'streams.held'   => 'payload (Push) streams currently held, live gauge',
    'watchers.held'  => 'poke (Notify) watchers currently held, live gauge',
    'store.get'      => 'document reads',
    'store.put'      => 'document writes',
    'store.put.bytes' => 'document bytes written',
    'store.delete'   => 'document deletes',
    'store.list'     => 'prefix listings',
    'store.fault.*'  => 'storage faults by kind (store.fault.Unavailable, ...)',
    'lock.acquired'  => 'locks granted',
    'lock.busy'      => 'lock attempts that found a holder (contention)',
    'lock.fault'     => 'locker failures other than contention',
    'workers'        => 'processes that flushed metrics recently, live census',
];

final readonly class MeteredBackend implements store\Backend, store\Movable, store\PrefixDeletable
{
    public function __construct(
        private store\Backend $inner,
        private Metrics       $metrics,
    ) {}

    public function get(string $path): string|store\FaultKind
    {
        $this->metrics->count('store.get');
        return $this->observe($this->inner->get($path));
    }

    public function put(string $path, string $bytes): true|store\FaultKind
    {
        $this->metrics->count('store.put');
        $this->metrics->count('store.put.bytes', \strlen($bytes));
        return $this->observe($this->inner->put($path, $bytes));
    }

    public function delete(string $path): true|store\FaultKind
    {
        $this->metrics->count('store.delete');
        return $this->observe($this->inner->delete($path));
    }

    public function list(string $prefix, ?string $cursor = null, int $limit = 100, bool $desc = false): store\Listing|store\FaultKind
    {
        $this->metrics->count('store.list');
        return $this->observe($this->inner->list($prefix, $cursor, $limit, $desc));
    }

    public function move(string $from, string $to): true|store\FaultKind
    {
        return $this->observe(store\move($this->inner, $from, $to));
    }

    public function deletePrefix(string $prefix): true|store\FaultKind
    {
        return $this->observe(store\delete_prefix($this->inner, $prefix));
    }

    private function observe(mixed $result): mixed
    {
        if ($result instanceof store\FaultKind && $result !== store\FaultKind::Missing) {
            $this->metrics->count('store.fault.' . $result->name);
        }
        return $result;
    }
}

final readonly class MeteredLocker implements store\Locker
{
    public function __construct(
        private store\Locker $inner,
        private Metrics      $metrics,
    ) {}

    public function lock(string $scope): store\Lock|store\FaultKind
    {
        $result = $this->inner->lock($scope);

        $this->metrics->count(match (true) {
            $result instanceof store\Lock          => 'lock.acquired',
            $result === store\FaultKind::Busy      => 'lock.busy',
            default                                => 'lock.fault',
        });

        return $result;
    }

    public function unlock(store\Lock $lock): void
    {
        $this->inner->unlock($lock);
    }
}
