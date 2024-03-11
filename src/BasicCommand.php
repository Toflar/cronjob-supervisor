<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor;

use Symfony\Component\Process\Process;

class BasicCommand implements CommandInterface
{
    public function __construct(
        private readonly string $identifier,
        private readonly int $numProcs,
        /** @var \Closure():Process */
        private readonly \Closure $createProcess,
    ) {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getNumProcs(): int
    {
        return $this->numProcs;
    }

    public function startNewProcess(): Process
    {
        $process = ($this->createProcess)();

        $process->start();

        return $process;
    }
}
