<?php

namespace vektr3\imp\bus;

//
// Durable ordered events with a resumable cursor, cross-process.
//

enum FaultKind
{
    case Busy;
    case BadTopic;
    case Unavailable;
    case Failed;
}

final readonly class Event
{
    public function __construct(
        public int    $seq,
        public string $bytes,
    ) {}
}

final readonly class Events
{
    public function __construct(
        /** @var list<Event> */
        public array $events,

        /**
         * The topic's head at read time; caught up when last seq equals it.
         */
        public int $head,
    ) {}
}

interface Bus
{
    public function publish(string $topic, string $bytes): int|FaultKind;
    public function signal(string $topic): int|FaultKind;
    public function since(string $topic, int $after, int $limit = 100): Events|FaultKind;
    public function head(string $topic): int|FaultKind;
}
