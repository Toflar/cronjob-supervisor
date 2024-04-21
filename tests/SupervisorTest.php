<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Test;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Toflar\CronjobSupervisor\Supervisor;

class SupervisorTest extends TestCase
{
    public function testCanSupervise(): void
    {
        $supervisor = Supervisor::withProviders(sys_get_temp_dir(), []);
        $this->assertFalse($supervisor->canSupervise());
        $this->assertFalse(Supervisor::canSuperviseWithProviders([]));
    }

    public function testSupervising(): void
    {
        $supervisor = Supervisor::withDefaultProviders(sys_get_temp_dir());
        if (!$supervisor->canSupervise()) {
            $this->markTestSkipped('Supervising is not supperted.');
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
        $fo = new \SplFileObject(__DIR__ . '/../var/storage/storage.json');
        $arrPids = json_decode($fo->current(), true);

        $pidsForChecking = array();
        foreach ($arrPids as $pids) {
            $pidsForChecking = array_merge($pidsForChecking, $pids);
        }

        $pidsOperatingSystem = array();

        (new Process(['pgrep', '-f', "[s]leep"]))
            ->run(function ($pid) use (&$pidsOperatingSystem) {
                $pidsOperatingSystem = preg_split('/\r\n|\r|\n/', trim($pid));
            });
        
        return count(array_intersect($pidsForChecking, $pidsOperatingSystem));
    }
}
