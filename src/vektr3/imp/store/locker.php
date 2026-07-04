<?php

namespace vektr3\imp\store;

//
// Single-writer enforcement
//

final readonly class Lock
{
    public function __construct(
        public string $scope,
        public mixed $handle = null,
    ) {}
}


interface Locker
{
    public function lock(string $scope): Lock|FaultKind;
    public function unlock(Lock $lock): void;
}
