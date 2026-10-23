<?php

declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Process\Process;
use Toflar\CronjobSupervisor\BasicCommand;
use Toflar\CronjobSupervisor\Provider\ProviderInterface;
use Toflar\CronjobSupervisor\Supervisor;

$providerClass = $argv[1] ?? '';
$directory = $argv[2] ?? '';
if (!is_a($providerClass, ProviderInterface::class, true) || '' === $directory) {
    throw new InvalidArgumentException('A supported provider class and isolated storage directory are required.');
}

$supervisor = Supervisor::withProviders($directory, [new $providerClass()], 1);

foreach (['short' => 1, 'medium' => 2, 'long' => 62] as $identifier => $duration) {
    $supervisor = $supervisor->withCommand(new BasicCommand(
        $identifier,
        2,
        static fn () => (new Process([PHP_BINARY, __DIR__.'/worker.php', $directory, $identifier, (string) $duration], timeout: null))->disableOutput(),
    ));
}

$supervisor->supervise();
