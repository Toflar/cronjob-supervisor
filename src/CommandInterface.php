<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor;

use Symfony\Component\Process\Process;

interface CommandInterface
{
    public function getIdentifier(): string;

    public function getNumProcs(): int;

    public function startNewProcess(): Process;
}
