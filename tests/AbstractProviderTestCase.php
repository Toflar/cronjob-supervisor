<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Toflar\CronjobSupervisor\Provider\ProviderInterface;
use Toflar\CronjobSupervisor\Supervisor;
use Toflar\CronjobSupervisor\Test\Fixtures\SequenceCommand;

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
