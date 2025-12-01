<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\Exception\ExceptionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\Process;
use Toflar\CronjobSupervisor\Provider\ProviderInterface;
use Toflar\CronjobSupervisor\Provider\PsProvider;
use Toflar\CronjobSupervisor\Provider\WindowsTaskListProvider;

class Supervisor
{
    private const DEFAULT_TICK_FREQUENCY = 10;

    private const LOCK_NAME = 'cronjob-supervisor-lock';

    private readonly LockFactory $lockFactory;

    private readonly Filesystem $filesystem;

    /**
     * @var array<string, array<int>>
     */
    private array $storage = [];

    /**
     * @var array<CommandInterface>
     */
    private array $commands = [];

    /**
     * @var array<int, Process>
     */
    private array $childProcesses = [];

    /**
     * @param array<ProviderInterface> $providers
     */
    private function __construct(
        private readonly string $storageDirectory,
        private readonly array $providers,
        private readonly int $tickFrequency = self::DEFAULT_TICK_FREQUENCY,
    ) {
        $this->lockFactory = new LockFactory(new FlockStore($storageDirectory));
        $this->filesystem = new Filesystem();

        $this->filesystem->mkdir($this->storageDirectory);
    }

    public static function withDefaultProviders(string $storageDirectory, int $tickFrequency = self::DEFAULT_TICK_FREQUENCY): self
    {
        return new self($storageDirectory, self::getDefaultProviders(), $tickFrequency);
    }

    public static function getDefaultProviders(): array
    {
        return [
            new WindowsTaskListProvider(),
            new PsProvider(),
        ];
    }

    /**
     * @param array<ProviderInterface> $providers
     */
    public static function withProviders(string $storageDirectory, array $providers, int $tickFrequency = self::DEFAULT_TICK_FREQUENCY): self
    {
        return new self($storageDirectory, $providers, $tickFrequency);
    }

    /**
     * @param array<ProviderInterface> $providers
     */
    public static function canSuperviseWithProviders(array $providers): bool
    {
        foreach ($providers as $provider) {
            if ($provider->isSupported()) {
                return true;
            }
        }

        return false;
    }

    public function canSupervise(): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->isSupported()) {
                return true;
            }
        }

        return false;
    }

    public function withCommand(CommandInterface $command): self
    {
        $clone = clone $this;
        $clone->commands[] = $command;

        return $clone;
    }

    public function supervise(\Closure|null $onTick = null): void
    {
        if (!$this->canSupervise()) {
            throw new \LogicException('No provider supported, cannot supervise!');
        }

        $end = time() + 55;
        $tick = 1;

        // Supervise for as long as we did not hit $end
        while (time() <= $end) {
            $this->doSupervise();

            // we check every $tickFrequency seconds whether we need to restart processes
            sleep($this->tickFrequency);

            if (null !== $onTick) {
                $onTick($tick);
            }

            ++$tick;
        }

        // Okay, we are done supervising. Now we might have child processes that are
        // still running. We have to wait for them to finish. Only then we can exit
        // ourselves otherwise we'd kill the children
        while ($this->hasRunningChildProcesses()) {
            sleep($this->tickFrequency);
        }
    }

    private function hasRunningChildProcesses(): bool
    {
        foreach ($this->childProcesses as $process) {
            if ($process->isRunning()) {
                return true;
            }
        }

        return false;
    }

    private function doSupervise(): void
    {
        $this->executeLocked(
            function (): void {
                if ($this->filesystem->exists($this->getStorageFile())) {
                    try {
                        $this->storage = json_decode(file_get_contents($this->getStorageFile()), true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        $this->storage = [];
                    }
                }

                // Update the storage with still running processes
                $this->checkRunningProcesses();

                // Pad commands
                foreach ($this->commands as $command) {
                    $this->padCommand($command);
                }

                // Save  state
                $this->filesystem->dumpFile($this->getStorageFile(), json_encode($this->storage, JSON_THROW_ON_ERROR));
            },
        );
    }

    private function checkRunningProcesses(): void
    {
        $storageNew = [];

        foreach ($this->storage as $commandLine => $pids) {
            foreach ($pids as $pid) {
                if ($this->isRunningPid($pid)) {
                    $storageNew[$commandLine][] = $pid;
                } else {
                    // Remove the PID from our own child processes if it's not running anymore
                    unset($this->childProcesses[$pid]);
                }
            }
        }

        $this->storage = $storageNew;
    }

    private function padCommand(CommandInterface $command): void
    {
        $running = !isset($this->storage[$command->getIdentifier()]) ? 0 : \count($this->storage[$command->getIdentifier()]);
        $required = $command->getNumProcs() - $running;

        if ($required > 0) {
            for ($i = 0; $i < $required; ++$i) {
                $process = $command->startNewProcess();

                if (null !== $process->getPid()) {
                    $this->storage[$command->getIdentifier()][] = $process->getPid();

                    // Remember started child processes because we have to remain running in order
                    // for those child processes not to get killed.
                    $this->childProcesses[$process->getPid()] = $process;
                }
            }
        }
    }

    private function getStorageFile(): string
    {
        return $this->storageDirectory.'/storage.json';
    }

    private function executeLocked(\Closure $closure): void
    {
        // Library is meant to be used with minutely cronjobs. Thus, the default ttl of
        // 300 is enough and does not need to be configurable.
        $lock = $this->lockFactory->createLock(self::LOCK_NAME);

        try {
            if (!$lock->acquire()) {
                return;
            }

            $closure();
            $lock->release();
        } catch (ExceptionInterface) {
            // Catch only lock component related exceptions. Noop.
        }
    }

    private function isRunningPid(int $pid): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->isSupported()) {
                return $provider->isPidRunning($pid);
            }
        }

        return false;
    }
}
