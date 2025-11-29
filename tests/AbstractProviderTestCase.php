<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Test;

use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Toflar\CronjobSupervisor\Provider\ProviderInterface;
use Toflar\CronjobSupervisor\Supervisor;

abstract class AbstractProviderTestCase extends TestCase
{
    /**
     * @var array<string>
     */
    protected array $tmpDataDirs = [];

    public function testCanSupervise(): void
    {
        $supervisor = Supervisor::withProviders(sys_get_temp_dir(), []);
        $this->assertFalse($supervisor->canSupervise());
        $this->assertFalse(Supervisor::canSuperviseWithProviders([]));
    }

    #[DataProvider('provideProviders')]
    public function testSupervising(ProviderInterface $provider): void
    {
        $supervisor = Supervisor::withProviders($this->createTemporaryDirectory(), [$provider]);
        if (!$supervisor->canSupervise()) {
            $this->fail('Cannot supervise with '.$provider::class);
        }

        $start = time();
        $php = (new PhpExecutableFinder())->find();

        $processes = [];

        // Simulate first cron
        $processes[] = $this->simulateRunner($php);

        // Simulate concurrent cron (this should NOT cause additional workers to be started!)
        $processes[] = $this->simulateRunner($php);

        // Simulate yet another concurrent cron (this should NOT cause additional workers
        // to be started!)
        $processes[] = $this->simulateRunner($php);

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
     * @return array<ProviderInterface>
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

    #[AfterClass]
    private function clearTemporaryDirectory(): void
    {
        $fs = new Filesystem();

        foreach (array_filter($this->tmpDataDirs) as $dir) {
            $fs->remove($dir);
        }
    }

    private function simulateRunner(string $php): Process
    {
        $p = new Process([$php, __DIR__.'/../var/runner.php']);
        $p->start(
            function (): void {
                $this->assertLessThanOrEqual(6, $this->countSleepProcesses());
            },
        );

        return $p;
    }

    private function countSleepProcesses(): int
    {
        $ps = new Process(['ps', 'aux']);
        $ps->run();

        $grep = (new Process(['grep', '[s]leep']))->setInput($ps->getOutput());
        $grep->run();

        $wc = (new Process(['wc', '-l']))->setInput($grep->getOutput());
        $wc->run();

        return (int) trim($wc->getOutput());
    }
}
