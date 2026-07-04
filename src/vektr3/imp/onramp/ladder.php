<?php

namespace vektr3\imp\onramp;

enum Rung: int
{
    case Stable = 0;
    case Elevated = 1;
    case High = 2;
    case Severe = 3;
    case Saturated = 4;
}

final readonly class LoadSample
{
    public function __construct(
        public float $connection_fill,
        public float $queue_fill,
        public float $shed_rate,
        public float $compute_fill,
    ) {}
}

interface LoadState
{
    public function rung(): Rung;
}

final class Ladder implements LoadState
{
    public Rung $rung = Rung::Stable;

    public int $calm_ticks = 0;

    public int $step_down_hold = 30;

    public function rung(): Rung
    {
        return $this->rung;
    }

    public function tick(LoadSample $sample): Rung
    {
        $target = $this->target($sample);

        if ($target->value > $this->rung->value) {
            $this->rung       = $target;
            $this->calm_ticks = 0;
        } elseif ($target->value < $this->rung->value) {
            $this->calm_ticks++;
            if ($this->calm_ticks >= $this->step_down_hold) {
                $this->rung       = Rung::from($this->rung->value - 1);
                $this->calm_ticks = 0;
            }
        } else {
            $this->calm_ticks = 0;
        }

        return $this->rung;
    }

    private function target(LoadSample $sample): Rung
    {
        $pressure = \max(
            $sample->connection_fill,
            $sample->queue_fill,
            $sample->compute_fill,
        );

        return match (true) {
            $pressure >= 1.00 => Rung::Saturated,
            $pressure >= 0.95 => Rung::Severe,
            $pressure >= 0.85 => Rung::High,
            $pressure >= 0.70 => Rung::Elevated,
            default           => Rung::Stable,
        };
    }
}
