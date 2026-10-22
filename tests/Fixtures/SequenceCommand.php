<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Test\Fixtures;

use Symfony\Component\Process\Process;
use Toflar\CronjobSupervisor\CommandInterface;

class SequenceCommand implements CommandInterface
{
    private int $nextProcess = 0;

    /**
     * @param list<Process> $processes
     */
    public function __construct(
        private readonly string $identifier,
        private readonly array $processes,
    ) {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getNumProcs(): int
    {
        return $this->nextProcess < \count($this->processes) ? 1 : 0;
    }

    public function startNewProcess(): Process
    {
        $process = $this->processes[$this->nextProcess++];
        $process->start();

        return $process;
    }
}
