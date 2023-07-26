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

    public function getNumProcs()
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
    ->withCommand(new SleepCommand(20, 4))
    ->supervise(function(int $tick) {
        echo 'Tick: ' . $tick;
    })
;