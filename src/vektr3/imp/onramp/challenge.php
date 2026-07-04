<?php

namespace vektr3\imp\onramp;

enum ChallengeKind
{
    case ProofOfWork;
    case Interactive;
    case Identity;
    case Attestation;
}

final readonly class Challenge
{
    public function __construct(
        public ChallengeKind $kind,
        public string        $blob,
        public int           $difficulty,
        public AdmissionKind $scope,
        public int           $expires_unix,
    ) {}
}

final readonly class Solution
{
    public function __construct(
        public string $blob,
        public string $answer,
        public float  $solve_ms,
    ) {}
}

interface Challenger
{
    public function issue(
        ClientInfo    $client,
        AdmissionKind $scope,
        ChallengeKind $kind,
        int           $difficulty,
    ): Challenge;

    public function verify(ClientInfo $client, Solution $solution): bool;
}
