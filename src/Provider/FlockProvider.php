<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Provider;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Toflar\CronjobSupervisor\Supervisor;

class FlockProvider implements ProviderInterface, InitInterface
{
    private const LOCK_NAME_PREFIX = 'cronjob-supervisor-flock-provider-lock-';

    private LockFactory|null $lockFactory = null;

    /**
     * @var array<int, LockInterface>
     */
    private array $locks = [];

    public function init(Supervisor $supervisor): void
    {
        $fs = new Filesystem();
        $storageDirectory = $supervisor->getStorageDirectory().'/flock-provider';
        $fs->mkdir($storageDirectory);
        $this->lockFactory = new LockFactory(new FlockStore($storageDirectory));

        // Make sure we clean up the directory when no processes are running which surely happens from time to time
        // giving us the ability to remove leftover lock files which Symfony does not do by default.
        $supervisor->on(
            Supervisor::EVENT_NO_PROCESSES_RUNNING,
            static function () use ($fs, $storageDirectory): void {
                $fs->remove($storageDirectory);
                $fs->mkdir($storageDirectory);
            },
        );

        $supervisor->on(Supervisor::EVENT_PROCESS_STARTED,
            function (int $pid): void {
                $this->lockPid($pid);
            },
        );

        $supervisor->on(
            Supervisor::EVENT_PROCESS_FINISHED,
            function (int $pid): void {
                $this->unlockPid($pid);
            },
        );
    }

    public function isSupported(): bool
    {
        return true;
    }

    public function isPidRunning(int $pid): bool
    {
        try {
            $lock = $this->getLockForPid($pid);

            // If the lock has been acquired by our process already, we refresh
            if ($lock->isAcquired()) {
                $lock->refresh();

                return true;
            }

            // If we can acquire the lock, it means it was released, so the process is not
            // running anymore
            if ($lock->acquire()) {
                $lock->release();

                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function lockPid(int $pid): void
    {
        $lock = $this->getLockForPid($pid);

        try {
            $lock->acquire();
        } catch (\Throwable) {
            // noop
        }
    }

    private function unlockPid(int $pid): void
    {
        $lock = $this->getLockForPid($pid);

        try {
            $lock->release();
        } catch (\Throwable) {
            // noop
        }

        unset($this->locks[$pid]);
    }

    private function getLockForPid(int $pid): LockInterface
    {
        if (null === $this->lockFactory) {
            throw new \InvalidArgumentException('Missing init() call.');
        }

        return $this->locks[$pid] ?? $this->locks[$pid] = $this->lockFactory->createLock(self::LOCK_NAME_PREFIX.$pid);
    }
}
