<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Toflar\CronjobSupervisor\CommandInterface;
use Toflar\CronjobSupervisor\Provider\ProviderInterface;
use Toflar\CronjobSupervisor\Supervisor;

abstract class AbstractProviderTestCase extends TestCase
{
    /**
     * @var array<string>
     */
    protected array $tmpDataDirs = [];

    /**
     * @var list<Process>
     */
    private array $workerProcesses = [];

    private int|null $noisyPid = null;

    protected function tearDown(): void
    {
        foreach ($this->workerProcesses as $process) {
            $process->stop(0);
        }

        $fs = new Filesystem();

        foreach (array_filter($this->tmpDataDirs) as $dir) {
            $fs->remove($dir);
        }
    }

    public function testCanSupervise(): void
    {
        $supervisor = Supervisor::withProviders(sys_get_temp_dir(), []);
        $this->assertFalse($supervisor->canSupervise());
        $this->assertFalse(Supervisor::canSuperviseWithProviders([]));
    }

    #[DataProvider('provideProviders')]
    public function testSupervising(string $providerClass): void
    {
        // This supervisor instance is not the one tested! The test happens in the runner.php
        $supervisor = Supervisor::withProviders($this->createTemporaryDirectory(), [new $providerClass()]);
        if (!$supervisor->canSupervise()) {
            $this->fail('Cannot supervise with '.$providerClass);
        }

        $start = time();
        $php = (new PhpExecutableFinder())->find();

        $processes = [];

        // Simulate first cron
        $processes[] = $this->simulateRunner($php, $providerClass);

        // Simulate concurrent cron (this should NOT cause additional workers to be started!)
        $processes[] = $this->simulateRunner($php, $providerClass);

        // Simulate yet another concurrent cron (this should NOT cause additional workers
        // to be started!)
        $processes[] = $this->simulateRunner($php, $providerClass);

        while (true) {
            $oneRunning = false;

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $oneRunning = true;
                }
            }

            if (!$oneRunning) {
                break;
            }

            sleep(5);
        }

        // The runner.php has a process that runs 100 seconds, so our supervisor must run
        // at least 100 seconds, otherwise it would've killed the child process
        $this->assertGreaterThanOrEqual(100, time() - $start);
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
            ->withCommand($this->oneShotCommand('waiting', $waiting))
            ->withCommand($this->oneShotCommand('noisy', $noisy))
            ->withCommand($this->oneShotCommand('between-ticks', $betweenTicks))
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
    public function testSupervisionPreservesConfiguredProcessTimeoutsWhenDraining(string $providerClass): void
    {
        $directory = $this->createTemporaryDirectory();
        $process = $this->createWorker($directory, 'timeout');
        $process->setTimeout(0.1);
        $supervisor = Supervisor::withProviders($directory, [new $providerClass()])
            ->withCommand($this->oneShotCommand('timeout', $process))
        ;

        $this->expectException(ProcessTimedOutException::class);
        $supervisor->supervise();
    }

    /**
     * @return array<string, array<int, class-string<ProviderInterface>>>
     */
    abstract public static function provideProviders(): iterable;

    protected function createTemporaryDirectory(): string
    {
        $dir = sys_get_temp_dir().'/'.uniqid('lt');
        $this->tmpDataDirs[] = $dir;

        $fs = new Filesystem();
        $fs->mkdir($dir);

        return $dir;
    }

    private function createWorker(string $directory, string $role): Process
    {
        return $this->workerProcesses[] = new Process([PHP_BINARY, __DIR__.'/Fixtures/child-process-worker.php', $directory, $role], timeout: null);
    }

    private function oneShotCommand(string $identifier, Process $process): CommandInterface
    {
        return new class($identifier, $process) implements CommandInterface {
            public function __construct(
                private readonly string $identifier,
                private readonly Process $process,
            ) {
            }

            public function getIdentifier(): string
            {
                return $this->identifier;
            }

            public function getNumProcs(): int
            {
                return $this->process->isStarted() ? 0 : 1;
            }

            public function startNewProcess(): Process
            {
                $this->process->start();

                return $this->process;
            }
        };
    }

    private function simulateRunner(string $php, string $providerClass): Process
    {
        $p = new Process([$php, __DIR__.'/../var/runner.php', $providerClass]);
        $p->start(
            function (): void {
                $this->assertSleepProcessCount(6);
            },
        );

        return $p;
    }

    private function assertSleepProcessCount(int $maxAllowed): void
    {
        $ps = new Process(['ps', 'aux']);
        $ps->run();

        $grep = (new Process(['grep', '[s]leep']))->setInput($ps->getOutput());
        $grep->run();
        $grepOutput = $grep->getOutput();

        $wc = (new Process(['wc', '-l']))->setInput($grepOutput);
        $wc->run();

        $count = (int) trim($wc->getOutput());
        $this->assertLessThanOrEqual($maxAllowed, $count, $grepOutput);
    }
}
