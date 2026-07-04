<?php

namespace vektr3\imp\worker;

use vektr3\imp;
use vektr3\imp\warden;
use vektr3\imp\metrics;
use vektr3\imp\onramp;

//
// The background-worker entrypoint: the loop a non-web process runs
// over the same Env as the web workers. Retention sweeps, job topics,
// scheduled work: anything that should not spend request time. It
// cooperates with FPM/resident workers purely through the store and
// bus, so it can run beside any of them, or not at all: everything
// here also degrades to opportunistic end-of-request work.
//

/**
 * Run until $until says stop (default: forever). Each pass: one
 * bounded warden sweep, the metrics duty (aggregate shards, tick the
 * ladder off $sampler, publish the rung), then each task, then sleep.
 * Tasks receive the Env and must do a bounded slice of work per call,
 * never block.
 *
 * $sampler turns the metrics aggregate into a LoadSample; how the
 * fills are computed (which gauges, which caps) is deployment policy.
 * Without one, the rung document is left alone: an operator (or
 * nobody) writes it.
 *
 * @param list<warden\Rule> $retention
 * @param array<string, callable(imp\Env): void> $tasks
 * @param ?callable(array): onramp\LoadSample $sampler
 */
function run(
    imp\Env   $env,
    array     $retention = [],
    array     $tasks = [],
    ?callable $sampler = null,
    int       $tick_us = 250_000,
    ?callable $until = null,
): void {
    $warden = new warden\Warden($env->store, [
        new warden\Rule('metrics/proc/', 120),
        ...$retention,
    ]);
    $ladder = new onramp\Ladder();

    while ($until === null || !$until()) {
        $now = \time();

        $warden->tick($now);
        metrics\aggregate($env->store, $env->locker, $now);

        if ($sampler !== null) {
            $agg = metrics\read_aggregate($env->store);
            if (\is_array($agg)) {
                $rung = $ladder->tick($sampler($agg));
                onramp\write_rung($env->store, $rung);
            }
        }

        foreach ($tasks as $task) {
            $task($env);
        }

        $env->metrics->flush();
        \usleep($tick_us);
    }
}
