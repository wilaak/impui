<?php

namespace vektr3\imp\onramp;

use vektr3\imp\store;

final readonly class StoreLoadState implements LoadState
{
    public function __construct(
        private store\Backend $store,
        private string $path = 'onramp/rung',
    ) {}

    public function rung(): Rung
    {
        $bytes = $this->store->get($this->path);

        return \is_string($bytes)
            ? (Rung::tryFrom((int) $bytes) ?? Rung::Stable)
            : Rung::Stable;
    }
}

function write_rung(store\Backend $store, Rung $rung, string $path = 'onramp/rung'): true|store\FaultKind
{
    return $store->put($path, (string) $rung->value);
}
