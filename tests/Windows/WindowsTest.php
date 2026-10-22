<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Test\Windows;

use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testWindowsMatchesPidsRegardlessOfExecutableName(): void
    {
        $process = $this->createMock(Process::class);
        $process
            ->method('mustRun')
            ->willReturnSelf()
        ;

        $process
            ->method('getOutput')
            ->willReturn(<<<'CSV'
                "php.exe","123","Console","1","1,234 K"
                "name,with,commas.exe","456","Console","1","2,345 K"
                "cmd.exe","789","Console","1","1,234 K"
                CSV)
        ;
        $provider = $this->getMockBuilder(WindowsTaskListProvider::class)->onlyMethods(['createProcess'])->getMock();
        $provider
            ->expects($this->once())
            ->method('createProcess')
            ->with(['tasklist', '/FO', 'CSV', '/NH'])
            ->willReturn($process)
        ;

        $this->assertSame([], $provider->getRunningPids([]));
        $this->assertSame([123, 456], $provider->getRunningPids([123, 456, 999]));
    }

    #[DataProvider('provideInvalidTaskListOutput')]
    public function testMalformedWindowsOutputIsRejected(string $output, string $error): void
    {
        $process = $this->createMock(Process::class);
        $process
            ->method('mustRun')
            ->willReturnSelf()
        ;

        $process
            ->method('getOutput')
            ->willReturn($output)
        ;

        $process
            ->method('getErrorOutput')
            ->willReturn($error)
        ;
        $provider = $this->getMockBuilder(WindowsTaskListProvider::class)->onlyMethods(['createProcess'])->getMock();
        $provider
            ->method('createProcess')
            ->willReturn($process)
        ;

        $this->assertNull($provider->getRunningPids([123]));
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function provideInvalidTaskListOutput(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'malformed' => ['unexpected localized error', ''];
        yield 'invalid PID' => ['"php.exe","not-a-pid","Console","1","100 K"', ''];
        yield 'partial result with warning' => ['"php.exe","123","Console","1","100 K"', 'Cannot inspect all requested processes.'];
    }

    public static function provideProviders(): \Iterator
    {
        yield 'tasklist' => [WindowsTaskListProvider::class];
        yield 'flock' => [FlockProvider::class];
    }
}
