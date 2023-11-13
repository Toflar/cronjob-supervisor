<?php

require_once 'vendor/autoload.php';

use Symfony\Component\Process\Process;
use Toflar\CronjobSupervisor\CommandInterface;
use Toflar\CronjobSupervisor\Supervisor;

class SleepCommand implements CommandInterface
{
    public function __construct(private int $sleep, private int $numProcs)
    {
    }

    public function getIdentifier(): string
    {
        return 'sleep ' . $this->sleep;
    }

    public function getNumProcs(): int
    {
        return $this->numProcs;
    }

    public function startNewProcess(): Process
    {
        $process = (new Process(['sleep' , $this->sleep]));
        $process->start();

        return $process;
    }
}

(new Supervisor(__DIR__ . '/storage'))
    ->withCommand(new SleepCommand(10, 2))
    ->withCommand(new SleepCommand(20, 2))
    ->withCommand(new SleepCommand(100, 2)) // Mock a process that will take longer than the 55 seconds of the supervisor itself
    ->supervise(function(int $tick) {
        echo 'Tick: ' . $tick;
    })
;