<?php

namespace vektr3\imp\metrics;

use vektr3\imp\store;

//
// Observation, split hot from cold. Recording is process-local memory
// (count/gauge cost an array write); flush() makes it visible as one
// small shard document per process; the aggregator folds shards into
// the system view every reader acts on (the ladder, the dashboard,
// introspection: the whole running-city picture grows from this
// document).
//
// Two kinds of numbers, deliberately distinct:
//
//   totals  durable, only ever grow, survive process death and
//           restarts (delta accounting against a ledger). Measure
//           over time by sampling totals twice.
//   gauges  point-in-time levels summed across live workers (held
//           streams, attached sessions). A dead worker's gauge is
//           gone, correctly.
//
// Metrics never fail the caller: recording is void and swallows
// faults. Losing a sample is always better than failing a request.
//

interface Metrics
{
    /**
     * Add to a counter, cumulative for this process's lifetime; the
     * aggregator turns it into a durable system total.
     */
    public function count(string $name, int $n = 1): void;

    /**
     * Set a level for this process; the aggregator sums levels across
     * live workers.
     */
    public function gauge(string $name, float $value): void;

    /**
     * Make recorded values visible to the aggregator. Cheap enough to
     * call at request end and every worker tick.
     */
    public function flush(): void;
}

final class NullMetrics implements Metrics
{
    public function count(string $name, int $n = 1): void {}
    public function gauge(string $name, float $value): void {}
    public function flush(): void {}
}

final class StoreMetrics implements Metrics
{
    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, float> */
    private array $gauges = [];

    private bool $dirty = false;

    /** Distinguishes this process lifetime from a recycled pid. */
    private readonly string $boot;

    public function __construct(
        private readonly store\Backend $store,
    ) {
        $this->boot = \bin2hex(\random_bytes(8));
    }

    public function count(string $name, int $n = 1): void
    {
        $this->counters[$name] = ($this->counters[$name] ?? 0) + $n;
        $this->dirty = true;
    }

    public function gauge(string $name, float $value): void
    {
        $this->gauges[$name] = $value;
        $this->dirty = true;
    }

    public function flush(): void
    {
        if (!$this->dirty) {
            return;
        }

        $this->store->put('metrics/proc/' . \getmypid(), \json_encode([
            'boot'     => $this->boot,
            'counters' => $this->counters,
            'gauges'   => $this->gauges,
            'unix'     => \time(),
        ]));

        $this->dirty = false;
    }
}

/**
 * Fold live shards into the system aggregate. Counters are folded as
 * deltas against a per-shard ledger into ever-growing totals: a boot
 * change (restart, recycled pid) contributes its full value, a dead
 * shard simply stops contributing, and nothing already counted is
 * ever lost or double-counted. Gauges are summed across live shards
 * only.
 *
 * Read-modify-write on the ledger, so it runs under a lock; Busy
 * means another aggregator got there first, which is fine.
 */
function aggregate(store\Backend $store, store\Locker $locker, int $now, int $stale_s = 60): true|store\FaultKind
{
    $lock = $locker->lock('metrics/agg');
    if ($lock instanceof store\FaultKind) {
        return $lock === store\FaultKind::Busy ? true : $lock;
    }

    try {
        return aggregate_locked($store, $now, $stale_s);
    } finally {
        $locker->unlock($lock);
    }
}

function aggregate_locked(store\Backend $store, int $now, int $stale_s): true|store\FaultKind
{
    $bytes  = $store->get('metrics/ledger');
    $ledger = \is_string($bytes) ? \json_decode($bytes, true) : [];
    if (!\is_array($ledger)) {
        $ledger = [];
    }
    $totals = \array_map(intval(...), $ledger['totals'] ?? []);
    $seen   = $ledger['seen'] ?? [];

    $listing = $store->list('metrics/proc/');
    if ($listing instanceof store\FaultKind) {
        return $listing;
    }

    $gauges  = [];
    $workers = 0;
    $fresh   = [];

    foreach ($listing->documents as $document) {
        $raw = $store->get($document->path);
        if (!\is_string($raw)) {
            continue;
        }
        $shard = \json_decode($raw, true);
        if (!\is_array($shard) || ($now - ($shard['unix'] ?? 0)) > $stale_s) {
            continue;
        }

        $pid  = \substr($document->path, \strrpos($document->path, '/') + 1);
        $boot = (string) ($shard['boot'] ?? '');
        $last = ($seen[$pid]['boot'] ?? null) === $boot
            ? ($seen[$pid]['counters'] ?? [])
            : [];

        foreach ($shard['counters'] ?? [] as $name => $value) {
            $delta = (int) $value - (int) ($last[$name] ?? 0);
            if ($delta > 0) {
                $totals[$name] = ($totals[$name] ?? 0) + $delta;
            }
        }
        foreach ($shard['gauges'] ?? [] as $name => $value) {
            $gauges[$name] = ($gauges[$name] ?? 0.0) + (float) $value;
        }

        $fresh[$pid] = ['boot' => $boot, 'counters' => $shard['counters'] ?? []];
        $workers++;
    }

    // Ledger entries for vanished shards are dropped: their deltas
    // are already in the totals.
    $put = $store->put('metrics/ledger', \json_encode([
        'totals' => $totals,
        'seen'   => $fresh,
    ]));
    if ($put !== true) {
        return $put;
    }

    return $store->put('metrics/agg', \json_encode([
        'totals'  => $totals,
        'gauges'  => $gauges,
        'workers' => $workers,
        'unix'    => $now,
    ]));
}

/**
 * The aggregate, for readers (the ladder sampler, the dashboard).
 * @return array{totals: array<string, int>, gauges: array<string, float>, workers: int, unix: int}|store\FaultKind
 */
function read_aggregate(store\Backend $store): array|store\FaultKind
{
    $bytes = $store->get('metrics/agg');
    if ($bytes === store\FaultKind::Missing) {
        return ['totals' => [], 'gauges' => [], 'workers' => 0, 'unix' => 0];
    }
    if ($bytes instanceof store\FaultKind) {
        return $bytes;
    }

    $agg = \json_decode($bytes, true);
    if (!\is_array($agg)) {
        return store\FaultKind::Corrupt;
    }

    // JSON erodes numeric types (3.0 -> 3): restore them at the boundary.
    return [
        'totals'  => \array_map(intval(...), $agg['totals'] ?? []),
        'gauges'  => \array_map(floatval(...), $agg['gauges'] ?? []),
        'workers' => (int) ($agg['workers'] ?? 0),
        'unix'    => (int) ($agg['unix'] ?? 0),
    ];
}
