<?php

require_once 'vendor/autoload.php';

use Symfony\Component\Process\Process;
use Toflar\CronjobSupervisor\BasicCommand;
use Toflar\CronjobSupervisor\Supervisor;

(Supervisor::withDefaultProviders(__DIR__ . '/storage'))
    ->withCommand(new BasicCommand('sleep 10', 2, function () {
        return new Process(['sleep', '10']);
    }))
    ->withCommand(new BasicCommand('sleep 20', 2, function() {
        return new Process(['sleep', '20']);
    }))
    ->withCommand(new BasicCommand('sleep 100', 2, function() {
        return new Process(['sleep', '100']);  // Mock a process that will take longer than the 55 seconds of the supervisor itself
    }))
    ->supervise(function(int $tick) {
        echo 'Tick: ' . $tick;
    })
;