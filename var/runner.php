<?php

require_once 'vendor/autoload.php';

use Symfony\Component\Process\Process;
use Toflar\CronjobSupervisor\BasicCommand;
use Toflar\CronjobSupervisor\Provider\ProviderInterface;
use Toflar\CronjobSupervisor\Supervisor;

$providerClass = $_SERVER['argv'][1] ?? '';

if (!is_a($providerClass, ProviderInterface::class, true)) {
    echo 'Must provide a correct providerClass';
    exit(1);
}

(Supervisor::withProviders(__DIR__ . '/storage', [new $providerClass()]))
    ->withCommand(new BasicCommand('sleep 10', 2, function () {
        return new Process(['sleep', '10'], timeout: null);
    }))
    ->withCommand(new BasicCommand('sleep 20', 2, function() {
        return new Process(['sleep', '20'], timeout: null);
    }))
    ->withCommand(new BasicCommand('sleep 100', 2, function() {
        return new Process(['sleep', '100'], timeout: null);  // Mock a process that will take longer than the 55 seconds of the supervisor itself
    }))
    ->supervise(function(int $tick) {
        echo 'Tick: ' . $tick;
    })
;