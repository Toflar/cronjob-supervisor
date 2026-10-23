<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Test\Windows;

use Symfony\Component\Process\Process;
use Toflar\CronjobSupervisor\Provider\FlockProvider;
use Toflar\CronjobSupervisor\Provider\WindowsTaskListProvider;
use Toflar\CronjobSupervisor\Test\AbstractProviderTestCase;

final class WindowsTest extends AbstractProviderTestCase
{
    public function testTaskListFindsPhpProcessesWithoutAShellWrapper(): void
    {
        $provider = new WindowsTaskListProvider();
        $this->assertTrue($provider->isPidRunning(getmypid()));
        $process = new Process([PHP_BINARY, '-r', 'usleep(5000000);'], timeout: null);
        $process->start();
        $pid = $process->getPid();
        $this->assertNotNull($pid);

        try {
            $this->assertTrue($provider->isPidRunning($pid));
        } finally {
            $process->stop(0);
        }

        $this->assertFalse($provider->isPidRunning($pid));
    }

    public static function provideProviders(): \Iterator
    {
        yield 'tasklist' => [WindowsTaskListProvider::class];
        yield 'flock' => [FlockProvider::class];
    }
}
