<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Test;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class SupervisorTest extends TestCase
{
    public function testSupervising(): void
    {
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
        $ps = new Process(['ps', 'aux']);
        $ps->run();

        $grep = (new Process(['grep', '[s]leep']))->setInput($ps->getOutput());
        $grep->run();

        $wc = (new Process(['wc', '-l']))->setInput($grep->getOutput());
        $wc->run();

        return (int) trim($wc->getOutput());
    }
}
