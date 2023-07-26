<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\Process;

class Supervisor
{
    private const LOCK_NAME = 'cronjob-supervisor-lock';

    private LockFactory $lockFactory;
    private Filesystem $filesystem;

    /**
     * @var array<string, array<int>>
     */
    private array $storage = [];

    /**
     * @var array<CommandInterface>
     */
    private array $commands = [];

    public function __construct(private string $storageDirectory)
    {
        $this->lockFactory = new LockFactory(new FlockStore($storageDirectory));
        $this->filesystem = new Filesystem();

        $this->filesystem->mkdir($this->storageDirectory);
    }

    public function withCommand(CommandInterface $command): self
    {
        $clone = clone $this;
        $clone->commands[] = $command;

        return $clone;
    }

    /**
     * @param int $onTick library is meant to be called every minute by a cronjob, so 55 seconds is default
     */
    public function supervise(\Closure|null $onTick = null): void
    {
        $end = time() + 55;
        $tick = 1;

        while (time() <= $end) {
            $this->doSupervise();

            // we check every 5 seconds whether we need to restart processes, that should be fine
            sleep(5);

            if (null !== $onTick) {
                $onTick($tick);
            }

            ++$tick;
        }
    }

    private function doSupervise(): void
    {
        $this->executeLocked(
            function (): void {
                if ($this->filesystem->exists($this->getStorageFile())) {
                    $this->storage = json_decode(file_get_contents($this->getStorageFile()), true);
                }

                // Check which processes are still running
                $storageNew = [];

                foreach ($this->storage as $commandLine => $pids) {
                    foreach ($pids as $pid) {
                        if ($this->isRunningPid($pid)) {
                            $storageNew[$commandLine][] = $pid;
                        }
                    }
                }

                // Pad commands
                foreach ($this->commands as $command) {
                    $storageNew = $this->padCommand($command, $storageNew);
                }

                // Save  state
                $this->storage = $storageNew;
                $this->filesystem->dumpFile($this->getStorageFile(), json_encode($storageNew));
            }
        );
    }

    private function padCommand(CommandInterface $command, array $storage): array
    {
        $running = !isset($storage[$command->getIdentifier()]) ? 0 : \count($storage[$command->getIdentifier()]);
        $required = $command->getNumProcs() - $running;

        if ($required > 0) {
            for ($i = 0; $i < $required; ++$i) {
                $process = $command->startNewProcess();

                if (null !== $process->getPid()) {
                    $storage[$command->getIdentifier()][] = $process->getPid();
                }
            }
        }

        return $storage;
    }

    private function getStorageFile(): string
    {
        return $this->storageDirectory.'/storage.json';
    }

    private function executeLocked(\Closure $closure): void
    {
        // Library is meant to be used with minutely cronjobs. Thus, the default ttl of 300 is enough and does not need
        // to be configurable.
        $lock = $this->lockFactory->createLock(self::LOCK_NAME);
        if (!$lock->acquire()) {
            return;
        }

        $closure();
        $lock->release();
    }

    private function isRunningPid(int $pid): bool
    {
        $process = new Process(['ps', '-p', $pid]);
        $exitCode = $process->run();

        if (0 !== $exitCode) {
            return false;
        }

        // Check for defunct output. If the process was started within this very process, it will still be listed,
        // although it's actually finished.
        if (str_contains($process->getOutput(), '<defunct>')) {
            return false;
        }

        return true;
    }
}
