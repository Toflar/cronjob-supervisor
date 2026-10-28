<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Toflar\CronjobSupervisor\BasicCommand;
use Toflar\CronjobSupervisor\Provider\BatchProviderInterface;
use Toflar\CronjobSupervisor\Provider\ProviderInterface;
use Toflar\CronjobSupervisor\Supervisor;
use Toflar\CronjobSupervisor\Test\Fixtures\SequenceCommand;

abstract class AbstractProviderTestCase extends TestCase
{
    /**
     * @var list<string>
     */
    protected array $tmpDataDirs = [];

    /**
     * @var list<Process>
     */
    private array $runners = [];

    /**
     * @var list<Process>
     */
    private array $workerProcesses = [];

    private int|null $noisyPid = null;

    /**
     * @var array<string, array<string, true>>
     */
    private array $seenWorkers = [];

    protected function tearDown(): void
    {
        foreach ($this->workerProcesses as $process) {
            $process->stop(0);
        }

        $fs = new Filesystem();

        foreach ($this->tmpDataDirs as $directory) {
            $fs->touch($directory.'/stop');
        }

        foreach ($this->runners as $runner) {
            $runner->stop(1);
        }

        // Give portable fixture workers time to notice the stop marker before removing their directory
        usleep(200000);
        $fs->remove($this->tmpDataDirs);
    }

    public function testCanSupervise(): void
    {
        $supervisor = Supervisor::withProviders($this->createTemporaryDirectory(), []);
        $this->assertFalse($supervisor->canSupervise());
        $this->assertFalse(Supervisor::canSuperviseWithProviders([]));
    }

    #[DataProvider('provideProviders')]
    public function testSupervising(string $providerClass): void
    {
        // This supervisor instance is not the one tested. The test happens in the fixture runner.php
        $directory = $this->createTemporaryDirectory();
        $supervisor = Supervisor::withProviders($directory, [new $providerClass()]);
        $this->assertTrue($supervisor->canSupervise(), 'Cannot supervise with '.$providerClass);
        $php = (new PhpExecutableFinder())->find();
        $this->assertIsString($php);
        $start = microtime(true);

        // Simulate first cron
        $this->runners[] = $this->simulateRunner($php, $providerClass, $directory);

        // Simulate concurrent cron. This should not cause additional workers to be started
        $this->runners[] = $this->simulateRunner($php, $providerClass, $directory);
        $this->waitForWorkers($directory);

        // Simulate another cron after workers are running. This must also respect the worker limit
        $this->runners[] = $this->simulateRunner($php, $providerClass, $directory);

        while ($this->pollRunners()) {
            $this->assertWorkerLimit($directory);
            usleep(100000);
        }

        foreach ($this->runners as $runner) {
            $this->assertSame(0, $runner->getExitCode(), $runner->getErrorOutput());
        }

        // The fixture runs a worker beyond the supervision window, so its parent must remain alive until it finishes
        $this->assertGreaterThanOrEqual(62, microtime(true) - $start);
        $this->assertGreaterThan(2, \count($this->seenWorkers['short']));
        $this->assertGreaterThan(2, \count($this->seenWorkers['medium']));
        $this->assertSame(['short' => 0, 'medium' => 0, 'long' => 0], $this->assertWorkerLimit($directory));
    }

    /**
     * @param class-string<ProviderInterface> $providerClass
     */
    #[DataProvider('provideProviders')]
    public function testSupervisionDrainsOutputAndReleasesFinishedLocks(string $providerClass): void
    {
        $directory = $this->createTemporaryDirectory();
        $fs = new Filesystem();
        $waiting = $this->createWorker($directory, 'waiting');
        $noisy = $this->createWorker($directory, 'noisy');
        $betweenTicks = $this->createWorker($directory, 'between-ticks');
        $supervisor = Supervisor::withProviders($directory, [new $providerClass()])
            ->withCommand(new SequenceCommand('waiting', [$waiting]))
            ->withCommand(new SequenceCommand('noisy', [$noisy]))
            ->withCommand(new SequenceCommand('between-ticks', [$betweenTicks]))
        ;
        $observer = new $providerClass();
        Supervisor::withProviders($directory, [$observer]);
        $finished = [];
        $supervisor->on(
            Supervisor::EVENT_PROCESS_FINISHED,
            function (int $pid) use ($directory, $fs, $observer, &$finished): void {
                $finished[] = $pid;
                if ($pid === $this->noisyPid) {
                    $this->assertFalse($observer->isPidRunning($pid));
                    $fs->touch($directory.'/finished');
                }
            },
        );
        $supervisor->supervise(
            function (int $tick) use ($directory, $fs, $noisy): void {
                if (1 === $tick) {
                    $this->assertTrue($fs->exists($directory.'/between-ticks-output-finished'));
                    $this->noisyPid = $noisy->getPid();
                    $this->assertNotNull($this->noisyPid);
                    $fs->touch($directory.'/supervising');
                }
            },
        );

        $this->assertCount(3, $finished);

        foreach ([$waiting, $noisy, $betweenTicks] as $process) {
            $this->assertSame(0, $process->getExitCode(), $process->getCommandLine());
        }

        foreach ([$noisy, $betweenTicks] as $process) {
            $this->assertSame(4 * 1024 * 1024, \strlen($process->getOutput()));
            $this->assertSame(4 * 1024 * 1024, \strlen($process->getErrorOutput()));
        }
    }

    /**
     * @param class-string<ProviderInterface> $providerClass
     */
    #[DataProvider('provideProviders')]
    public function testTimeoutsAreHandledDuringPollingAndDrainingWithoutInterruptingSiblings(string $providerClass): void
    {
        $directory = $this->createTemporaryDirectory();
        $general = $this->createWorker($directory, 'timeout')->setTimeout(0.1);
        $replacement = $this->createWorker($directory, 'between-ticks');
        $idle = $this->createWorker($directory, 'timeout')->setIdleTimeout(0.1);
        $draining = $this->createWorker($directory, 'timeout')->setTimeout(55.1);
        $healthy = $this->createWorker($directory, 'waiting');
        $supervisor = Supervisor::withProviders($directory, [new $providerClass()])
            ->withCommand(new SequenceCommand('general', [$general, $replacement]))
            ->withCommand(new SequenceCommand('idle', [$idle]))
            ->withCommand(new SequenceCommand('draining', [$draining]))
            ->withCommand(new SequenceCommand('healthy', [$healthy]))
        ;
        $timeouts = [];
        $finished = [];
        $fs = new Filesystem();
        $supervisor->on(
            Supervisor::EVENT_PROCESS_TIMED_OUT,
            function (int $pid, ProcessTimedOutException $exception) use (&$timeouts, $draining, $healthy, $fs, $directory): void {
                $timeouts[] = $exception;
                if ($exception->getProcess() === $draining) {
                    $this->assertTrue($healthy->isRunning());
                    $fs->touch($directory.'/finished');
                }
            },
        );
        $supervisor->on(
            Supervisor::EVENT_PROCESS_FINISHED,
            static function (int $pid) use (&$finished): void {
                $finished[] = $pid;
            },
        );

        $supervisor->supervise(
            function (int $tick) use (&$timeouts, $general, $idle, $replacement, $fs, $directory): void {
                if (1 === $tick) {
                    $this->assertCount(2, $timeouts);
                    $this->assertFalse($general->isRunning());
                    $this->assertFalse($idle->isRunning());
                }

                if (2 === $tick) {
                    $this->assertTrue($replacement->isStarted());
                    $this->assertTrue($fs->exists($directory.'/between-ticks-output-finished'));
                }
            },
        );

        $this->assertCount(3, $timeouts);

        foreach ([[$general, true], [$idle, false], [$draining, true]] as [$process, $isGeneral]) {
            $matching = array_values(array_filter($timeouts, static fn (ProcessTimedOutException $exception): bool => $exception->getProcess() === $process));
            $this->assertCount(1, $matching);
            $this->assertSame($isGeneral, $matching[0]->isGeneralTimeout());
            $this->assertSame(!$isGeneral, $matching[0]->isIdleTimeout());
        }

        $this->assertCount(5, $finished);
        $this->assertSame(0, $healthy->getExitCode());
        $this->assertSame(0, $replacement->getExitCode());
    }

    /**
     * @param class-string<ProviderInterface> $providerClass
     */
    #[DataProvider('providePidCheckProviders')]
    public function testReusedPidIsCheckedWithoutTheFinishedChildObject(string $providerClass): void
    {
        $directory = $this->createTemporaryDirectory();
        $pid = 12345;
        $ownedRunning = true;
        $process = $this->createMock(Process::class);
        $process
            ->expects($this->once())
            ->method('start')
        ;

        $process
            ->method('getPid')
            ->willReturn($pid)
        ;
        $process
            ->method('isRunning')
            ->willReturnCallback(
                static function () use (&$ownedRunning): bool {
                    return $ownedRunning;
                },
            )
        ;
        $provider = $this->createMock($providerClass);
        $provider
            ->method('isSupported')
            ->willReturn(true)
        ;

        if ($provider instanceof BatchProviderInterface) {
            $provider
                ->expects($this->atLeastOnce())
                ->method('getRunningPids')
                ->with([$pid])
                ->willReturn([$pid])
            ;

            $provider
                ->expects($this->never())
                ->method('isPidRunning')
            ;
        } else {
            $provider
                ->expects($this->atLeastOnce())
                ->method('isPidRunning')
                ->with($pid)
                ->willReturn(true)
            ;
        }

        $supervisor = Supervisor::withProviders($directory, [$provider], 1)
            ->withCommand(new BasicCommand('worker', 1, static fn (): Process => $process))
        ;
        $finished = [];
        $supervisor->on(
            Supervisor::EVENT_PROCESS_FINISHED,
            static function (int $finishedPid) use (&$finished): void {
                $finished[] = $finishedPid;
            },
        );
        $this->superviseForTicks(
            $supervisor,
            2,
            static function () use (&$ownedRunning): void {
                // Another runner has replaced our finished worker with a new process using the same PID
                $ownedRunning = false;
            },
        );

        $this->assertSame([$pid], $finished);
        $this->assertSame(['worker' => [$pid]], json_decode((string) file_get_contents($directory.'/storage.json'), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{class-string<ProviderInterface>}>
     */
    public static function providePidCheckProviders(): iterable
    {
        yield 'individual' => [ProviderInterface::class];
        yield 'batch' => [BatchProviderInterface::class];
    }

    public function testBatchChecksRefreshAcrossCommandsAndExcludeOwnedChildren(): void
    {
        $directory = $this->createTemporaryDirectory();
        $fs = new Filesystem();
        $fs->dumpFile($directory.'/storage.json', '{"one":[10000000],"two":[10000001]}');
        $requests = [];
        $provider = $this->createMock(BatchProviderInterface::class);
        $provider
            ->method('isSupported')
            ->willReturn(true)
        ;

        $provider
            ->expects($this->never())
            ->method('isPidRunning')
        ;
        $provider
            ->expects($this->exactly(2))
            ->method('getRunningPids')
            ->willReturnCallback(
                static function (array $pids) use (&$requests): array {
                    $requests[] = $pids;

                    return 1 === \count($requests) ? [10000000] : [];
                },
            )
        ;
        $owned = $this->createMock(Process::class);
        $owned
            ->expects($this->once())
            ->method('start')
        ;

        $owned
            ->method('getPid')
            ->willReturn(10000002)
        ;

        $owned
            ->method('isRunning')
            ->willReturn(true)
        ;

        $supervisor = Supervisor::withProviders($directory, [$provider], 1)
            ->withCommand(new BasicCommand('owned', 1, static fn (): Process => $owned))
        ;

        $this->superviseForTicks($supervisor, 2);

        $this->assertSame([[10000000, 10000001], [10000000]], $requests);
        $this->assertSame(['owned' => [10000002]], json_decode((string) file_get_contents($directory.'/storage.json'), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testFailedBatchChecksFallBackToIndividualPidChecks(): void
    {
        $directory = $this->createTemporaryDirectory();
        (new Filesystem())->dumpFile($directory.'/storage.json', '{"worker":[10000000]}');
        $provider = $this->createMock(BatchProviderInterface::class);
        $provider
            ->method('isSupported')
            ->willReturn(true)
        ;

        $provider
            ->expects($this->once())
            ->method('getRunningPids')
            ->willReturn(null)
        ;

        $provider
            ->expects($this->once())
            ->method('isPidRunning')
            ->with(10000000)
            ->willReturn(true)
        ;
        $supervisor = Supervisor::withProviders($directory, [$provider], 1)
            ->withCommand(new BasicCommand(
                'worker',
                1,
                function (): Process {
                    $this->fail('A running worker must not be replaced when the batch query fails.');
                },
            ))
        ;

        $this->superviseForTicks($supervisor, 1);

        $this->assertSame(['worker' => [10000000]], json_decode((string) file_get_contents($directory.'/storage.json'), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array<int, class-string<ProviderInterface>>>
     */
    abstract public static function provideProviders(): iterable;

    protected function createTemporaryDirectory(): string
    {
        $dir = sys_get_temp_dir().'/cronjob-supervisor-integration-'.bin2hex(random_bytes(8));
        $this->tmpDataDirs[] = $dir;
        (new Filesystem())->mkdir($dir);

        return $dir;
    }

    private function superviseForTicks(Supervisor $supervisor, int $ticks, \Closure|null $onTick = null): void
    {
        $done = new \RuntimeException('Test supervision complete.');

        try {
            $supervisor->supervise(
                static function (int $tick) use ($ticks, $done, $onTick): void {
                    $onTick?->__invoke($tick);
                    if ($tick === $ticks) {
                        throw $done;
                    }
                },
            );
        } catch (\RuntimeException $exception) {
            if ($exception !== $done) {
                throw $exception;
            }
        }
    }

    private function createWorker(string $directory, string $role): Process
    {
        return $this->workerProcesses[] = new Process([PHP_BINARY, __DIR__.'/Fixtures/child-process-worker.php', $directory, $role], timeout: null);
    }

    private function simulateRunner(string $php, string $providerClass, string $directory): Process
    {
        $process = new Process([$php, __DIR__.'/Fixtures/runner.php', $providerClass, $directory], timeout: 90);
        $process->start();

        return $process;
    }

    private function pollRunners(): bool
    {
        $running = false;

        foreach ($this->runners as $runner) {
            $runner->checkTimeout();
            if ($runner->isRunning()) {
                $running = true;
            } else {
                $this->assertSame(0, $runner->getExitCode(), $runner->getErrorOutput());
            }
        }

        return $running;
    }

    private function waitForWorkers(string $directory): void
    {
        $deadline = microtime(true) + 10;

        while (true) {
            $this->assertTrue($this->pollRunners(), 'Runners exited before reaching the required worker count.');
            $counts = $this->assertWorkerLimit($directory);
            if (['short' => 2, 'medium' => 2, 'long' => 2] === $counts) {
                (new Filesystem())->touch($directory.'/release');

                return;
            }

            $this->assertLessThan($deadline, microtime(true), 'Workers did not reach the configured count.');
            usleep(100000);
        }
    }

    /**
     * @return array<string, int>
     */
    private function assertWorkerLimit(string $directory): array
    {
        $counts = ['short' => 0, 'medium' => 0, 'long' => 0];

        foreach (glob($directory.'/workers/*.json') ?: [] as $file) {
            $contents = @file_get_contents($file);
            if (false === $contents) {
                continue;
            }

            $marker = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey($marker['identifier'], $counts);
            ++$counts[$marker['identifier']];
            $this->seenWorkers[$marker['identifier']][basename($file)] = true;
        }

        foreach ($counts as $identifier => $count) {
            $this->assertLessThanOrEqual(2, $count, 'Too many '.$identifier.' workers.');
        }

        return $counts;
    }
}
