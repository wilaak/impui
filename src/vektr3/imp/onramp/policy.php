<?php

namespace vektr3\imp\onramp;

enum AdmissionKind
{
    case Session;
    case Stream;
}

enum Tier: int
{
    case Anonymous = 0;
    case Recognized = 1;
    case Registered = 2;
    case Prioritized = 3;
}

final readonly class ClientInfo
{
    public function __construct(
        public string  $ip,
        public ?string $session_id = null,
        public Tier    $tier = Tier::Anonymous,

        public ?string $asn        = null,
        public ?string $country    = null,
        public ?string $user_agent = null,

        public ?float $reputation    = null,
        public ?float $last_solve_ms = null,
        public int    $live_sessions = 0,
        public int    $live_streams  = 0,
    ) {}
}

interface Admission
{
    public function check(ClientInfo $client, AdmissionKind $kind): Verdict;
}
