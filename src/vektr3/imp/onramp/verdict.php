<?php

namespace vektr3\imp\onramp;

enum RejectReason
{
    case ConnectionCap;
    case ClientCap;
    case SessionRate;
    case OverBudget;
    case Saturated;
    case Denied;
}

abstract readonly class Verdict {}

final readonly class Admit extends Verdict {}

final readonly class Challenged extends Verdict
{
    public function __construct(
        public Challenge $challenge,
    ) {}
}

final readonly class Queued extends Verdict
{
    public function __construct(
        public Ticket $ticket,
    ) {}
}

final readonly class Rejected extends Verdict
{
    public function __construct(
        public RejectReason $reason,
        public ?int         $retry_after_s = null,
    ) {}
}
