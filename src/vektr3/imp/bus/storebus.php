<?php

namespace vektr3\imp\bus;

use vektr3\imp\store;

final readonly class StoreBus implements Bus
{
    public function __construct(
        private store\Backend $store,
        private store\Locker  $locker,
        /**
         * Events kept per topic; the window a slow consumer can miss.
         */
        private int $keep = 1000,
    ) {}

    public function publish(string $topic, string $bytes): int|FaultKind
    {
        return store_append($this->store, $this->locker, $topic, $bytes, $this->keep);
    }

    public function signal(string $topic): int|FaultKind
    {
        return store_append($this->store, $this->locker, $topic, null, $this->keep);
    }

    public function since(string $topic, int $after, int $limit = 100): Events|FaultKind
    {
        return store_since($this->store, $topic, $after, $limit);
    }

    public function head(string $topic): int|FaultKind
    {
        return store_head($this->store, $topic);
    }
}

function store_head(store\Backend $store, string $topic): int|FaultKind
{
    $bytes = $store->get("bus/$topic/head");

    return match (true) {
        $bytes === store\FaultKind::Missing => 0,
        $bytes instanceof store\FaultKind   => store_fault($bytes),
        default                             => (int) $bytes,
    };
}

function store_since(
    store\Backend $store,
    string $topic,
    int $after,
    int $limit = 100
): Events|FaultKind
{
    $head = store_head($store, $topic);
    if ($head instanceof FaultKind) {
        return $head;
    }
    if ($head <= $after) {
        return new Events([], $head);
    }

    $listing = $store->list(
        "bus/$topic/e/",
        cursor: "bus/$topic/e/" . seq_pad($after),
        limit:  $limit,
    );
    if ($listing instanceof store\FaultKind) {
        return store_fault($listing);
    }

    $events = [];
    foreach ($listing->documents as $document) {
        $bytes = $store->get($document->path);
        if ($bytes === store\FaultKind::Missing) {
            continue;   // trimmed between list and get
        }
        if ($bytes instanceof store\FaultKind) {
            return store_fault($bytes);
        }

        $events[] = new Event(seq_of($document->path), $bytes);
    }

    return new Events($events, $head);
}

function store_append(
    store\Backend $store,
    store\Locker $locker,
    string $topic,
    ?string $bytes,
    int $keep
): int|FaultKind
{
    $lock = $locker->lock("bus/$topic");
    if ($lock instanceof store\FaultKind) {
        return store_fault($lock);
    }

    $seq = store_append_locked($store, $topic, $bytes, $keep);

    $locker->unlock($lock);
    return $seq;
}

function store_append_locked(
    store\Backend $store,
    string $topic,
    ?string $bytes,
    int $keep
): int|FaultKind
{
    $head = store_head($store, $topic);
    if ($head instanceof FaultKind) {
        return $head;
    }

    $seq = $head + 1;

    if ($bytes !== null) {
        $put = $store->put("bus/$topic/e/" . seq_pad($seq), $bytes);
        if ($put instanceof store\FaultKind) {
            return store_fault($put);
        }
    }

    $put = $store->put("bus/$topic/head", (string) $seq);
    if ($put instanceof store\FaultKind) {
        return store_fault($put);
    }

    if ($seq > $keep) {
        $store->delete("bus/$topic/e/" . seq_pad($seq - $keep));
    }

    return $seq;
}

function store_fault(store\FaultKind $kind): FaultKind
{
    return match ($kind) {
        store\FaultKind::Busy        => FaultKind::Busy,
        store\FaultKind::BadPath     => FaultKind::BadTopic,
        store\FaultKind::Unavailable => FaultKind::Unavailable,
        default                      => FaultKind::Failed,
    };
}

function seq_pad(int $seq): string
{
    return \str_pad((string) $seq, 20, '0', \STR_PAD_LEFT);
}

function seq_of(string $path): int
{
    return (int) \substr($path, \strrpos($path, '/') + 1);
}
