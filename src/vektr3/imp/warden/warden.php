<?php

namespace vektr3\imp\warden;

use vektr3\imp\store;

//
// Retention. The store has no TTLs on purpose: expiry is policy, and
// policy lives here, in one place, as per-prefix rules instead of
// scattered across put() call sites.
//
// Sweeping is incremental and budgeted: a few pages per tick, cursor
// carried between ticks, so the warden is a slow janitor that can
// never stall a tick. Write-path retention (the bus trimming its own
// window) handles the hot cases; the warden catches the stragglers:
// abandoned sessions, orphaned records, dead tickets.
//
// Deletes race writers benignly: age thresholds are minutes to days,
// so a document modified between stat and delete is simply respawned
// by its writer, and atomic put means nothing tears.
//

final readonly class Rule
{
    public function __construct(
        public string $prefix,
        public int    $max_age_s,
    ) {}
}

final class Warden
{
    /** @var array<string, ?string> Sweep cursor per prefix, carried between ticks. */
    private array $cursors = [];

    public function __construct(
        private readonly store\Backend $store,

        /**
         * @var list<Rule>
         */
        private readonly array $rules,

        private readonly int $page = 200,
    ) {}

    /**
     * One bounded sweep step: at most one page per rule per tick.
     * Returns the number of documents deleted.
     */
    public function tick(int $now): int|store\FaultKind
    {
        $deleted = 0;

        foreach ($this->rules as $rule) {
            $swept = $this->sweep($rule, $now);
            if ($swept instanceof store\FaultKind) {
                return $swept;
            }
            $deleted += $swept;
        }

        return $deleted;
    }

    private function sweep(Rule $rule, int $now): int|store\FaultKind
    {
        $cursor  = $this->cursors[$rule->prefix] ?? null;
        $listing = $this->store->list($rule->prefix, $cursor, $this->page);
        if ($listing instanceof store\FaultKind) {
            return $listing;
        }

        $deleted = 0;
        foreach ($listing->documents as $document) {
            if ($now - $document->modified_unix < $rule->max_age_s) {
                continue;
            }

            $result = $this->store->delete($document->path);
            if ($result instanceof store\FaultKind) {
                return $result;
            }
            $deleted++;
        }

        // null cursor wraps to the start: the sweep is a ring.
        $this->cursors[$rule->prefix] = $listing->cursor;

        return $deleted;
    }
}
