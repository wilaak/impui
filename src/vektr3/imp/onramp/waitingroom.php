<?php

namespace vektr3\imp\onramp;

final readonly class Ticket
{
    public function __construct(
        public string $token,
        public Tier   $tier,
        public int    $position,
        public int    $poll_after_s,
        public int    $expires_unix,
    ) {}
}

enum TicketStatus
{
    case Waiting;
    case Ready;
    case Gone;
}

final readonly class RoomFull {}

interface WaitingRoom
{
    public function enter(ClientInfo $client): Ticket|RoomFull;

    public function poll(string $token): TicketStatus;

    public function release(int $count): void;
}
