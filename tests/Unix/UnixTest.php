<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Test\Unix;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Toflar\CronjobSupervisor\Provider\FlockProvider;
use Toflar\CronjobSupervisor\Provider\PsProvider;
use Toflar\CronjobSupervisor\Test\AbstractProviderTestCase;

final class UnixTest extends AbstractProviderTestCase
{
    public function testOnePsQueryChecksOneHundredPidsAndExcludesZombies(): void
    {
        $pids = range(10000000, 10000099);
        $output = '';

        foreach ($pids as $pid) {
            $output .= $pid.' S'."\n";
        }
        $output .= "10000100 Z\n";
        $process = $this->createMock(Process::class);
        $process
            ->method('run')
            ->willReturn(0)
        ;

        $process
            ->method('getOutput')
            ->willReturn($output)
        ;
        $provider = $this->getMockBuilder(PsProvider::class)->onlyMethods(['createProcess'])->getMock();
        $provider
            ->expects($this->once())
            ->method('createProcess')
            ->with(['ps', '-p', implode(',', [...$pids, 10000100]), '-o', 'pid=', '-o', 'stat='])
            ->willReturn($process)
        ;

        $this->assertSame($pids, $provider->getRunningPids([...$pids, 10000100]));
    }

    public function testPsRequestsAreBoundedAndEmptyRequestsLaunchNothing(): void
    {
        $process = $this->createMock(Process::class);
        $process
            ->method('run')
            ->willReturn(1)
        ;

        $process
            ->method('getOutput')
            ->willReturn('')
        ;

        $process
            ->method('getErrorOutput')
            ->willReturn('')
        ;
        $requests = [];
        $provider = $this->getMockBuilder(PsProvider::class)->onlyMethods(['createProcess'])->getMock();
        $provider
            ->expects($this->exactly(2))
            ->method('createProcess')
            ->willReturnCallback(
                static function (array $command) use ($process, &$requests): Process {
                    $requests[] = $command;

                    return $process;
                },
            )
        ;

        $this->assertSame([], $provider->getRunningPids([]));
        $this->assertSame([], $provider->getRunningPids(range(10000000, 10000300)));
        $this->assertSame(
            [
                ['ps', '-p', implode(',', range(10000000, 10000255)), '-o', 'pid=', '-o', 'stat='],
                ['ps', '-p', implode(',', range(10000256, 10000300)), '-o', 'pid=', '-o', 'stat='],
            ],
            $requests,
        );
    }

    public function testPsFailureDiscardsPartialResults(): void
    {
        $process = $this->createMock(Process::class);
        $process
            ->method('run')
            ->willReturnOnConsecutiveCalls(0, 1)
        ;

        $process
            ->method('getOutput')
            ->willReturn('10000000 S')
        ;

        $process
            ->method('getErrorOutput')
            ->willReturnOnConsecutiveCalls('', 'permission denied')
        ;
        $provider = $this->getMockBuilder(PsProvider::class)->onlyMethods(['createProcess'])->getMock();
        $provider
            ->expects($this->exactly(2))
            ->method('createProcess')
            ->willReturn($process)
        ;

        $this->assertNull($provider->getRunningPids(range(10000000, 10000300)));
    }

    #[DataProvider('provideInvalidPsOutput')]
    public function testMalformedPsOutputIsRejected(string $output, string $error): void
    {
        $process = $this->createMock(Process::class);
        $process
            ->method('run')
            ->willReturn(0)
        ;

        $process
            ->method('getOutput')
            ->willReturn($output)
        ;

        $process
            ->method('getErrorOutput')
            ->willReturn($error)
        ;
        $provider = $this->getMockBuilder(PsProvider::class)->onlyMethods(['createProcess'])->getMock();
        $provider
            ->method('createProcess')
            ->willReturn($process)
        ;

        $this->assertNull($provider->getRunningPids([123]));
    }

    public function testNativePsBatchContainsCurrentProcess(): void
    {
        $provider = new PsProvider();
        if (!$provider->isSupported()) {
            $this->markTestSkipped('Requires Unix ps.');
        }

        $exited = new Process([PHP_BINARY, '-r', 'usleep(50000);']);
        $exited->start();
        $pid = $exited->getPid();
        $this->assertNotNull($pid);
        $exited->wait();
        $this->assertSame([getmypid()], $provider->getRunningPids([getmypid(), $pid]));
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function provideInvalidPsOutput(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'malformed' => ['unexpected output', ''];
        yield 'truncated' => ['123', ''];
        yield 'partial result with warning' => ['123 S', 'Cannot inspect all requested processes.'];
    }

    public static function provideProviders(): \Iterator
    {
        yield 'ps' => [PsProvider::class];
        yield 'flock' => [FlockProvider::class];
    }
}
