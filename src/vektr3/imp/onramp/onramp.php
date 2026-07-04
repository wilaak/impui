<?php

namespace vektr3\imp\onramp;

final readonly class Caps
{
    public function __construct(
        public int $streams_per_client  = 4,
        public int $sessions_per_client = 16,
    ) {}
}

final readonly class DefaultAdmission implements Admission
{
    public function __construct(
        private LoadState    $load,
        private Caps         $caps = new Caps(),
        private ?Challenger  $challenger = null,
        private ?WaitingRoom $room = null,
        private int          $base_difficulty = 12,
    ) {}

    public function check(ClientInfo $client, AdmissionKind $kind): Verdict
    {
        if ($kind === AdmissionKind::Stream && $client->live_streams >= $this->caps->streams_per_client) {
            return new Rejected(RejectReason::ClientCap);
        }
        if ($kind === AdmissionKind::Session && $client->live_sessions >= $this->caps->sessions_per_client) {
            return new Rejected(RejectReason::ClientCap);
        }

        $rung    = $this->load->rung();
        $trusted = $client->tier->value >= Tier::Registered->value;

        return match ($rung) {
            Rung::Stable,
            Rung::Elevated  => new Admit(),
            Rung::High      => $trusted ? new Admit() : $this->gate($client, $kind),
            Rung::Severe,
            Rung::Saturated => new Rejected(RejectReason::Saturated, retry_after_s: 30),
        };
    }

    private function gate(ClientInfo $client, AdmissionKind $kind): Verdict
    {
        if ($this->challenger !== null) {
            $difficulty = $this->base_difficulty - (2 * $client->tier->value);

            return new Challenged($this->challenger->issue(
                $client,
                $kind,
                ChallengeKind::ProofOfWork,
                $difficulty,
            ));
        }

        $ticket = $this->room?->enter($client);
        if ($ticket instanceof Ticket) {
            return new Queued($ticket);
        }

        return new Rejected(RejectReason::Saturated, retry_after_s: 5);
    }
}
