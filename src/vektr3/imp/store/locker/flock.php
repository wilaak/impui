<?php

namespace vektr3\imp\store\locker;

use vektr3\imp\store;

/**
 * Note: the lock directory must be on a local filesystem as flock over NFS is unreliable.
 */
final readonly class FlockLocker implements store\Locker
{
    public function __construct(
        private string $dir,
    ) {}

    public function lock(string $scope): store\Lock|store\FaultKind
    {
        $fault = store\path_fault($scope);
        if ($fault !== null) {
            return $fault;
        }

        if (!\is_dir($this->dir) && !@\mkdir($this->dir, 0770, true) && !\is_dir($this->dir)) {
            return store\FaultKind::Denied;
        }

        $file = $this->dir . '/' . \str_replace('/', "\x1f", $scope) . '.lock';

        $handle = @\fopen($file, 'c');
        if ($handle === false) {
            return store\FaultKind::Unavailable;
        }

        if (!\flock($handle, \LOCK_EX | \LOCK_NB)) {
            \fclose($handle);
            return store\FaultKind::Busy;
        }

        return new store\Lock($scope, handle: $handle);
    }

    public function unlock(store\Lock $lock): void
    {
        if (\is_resource($lock->handle)) {
            \flock($lock->handle, \LOCK_UN);
            \fclose($lock->handle);
        }
    }
}
