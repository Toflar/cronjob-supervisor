<?php

declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Filesystem\Filesystem;

$directory = $argv[1] ?? '';
$role = $argv[2] ?? '';
if ('' === $directory || !in_array($role, ['waiting', 'noisy', 'between-ticks', 'timeout'], true)) {
    throw new InvalidArgumentException('A directory and supported worker role are required.');
}

if ('timeout' === $role) {
    sleep(70);
    exit(0);
}

$fs = new Filesystem();
if ('waiting' === $role || 'noisy' === $role) {
    $marker = $directory.('waiting' === $role ? '/finished' : '/supervising');
    $deadline = hrtime(true) + 120000000000;

    while (!$fs->exists($marker)) {
        if (hrtime(true) >= $deadline) {
            exit(42);
        }

        usleep(10000);
    }
}

if ('noisy' === $role) {
    // Wait beyond the supervision window after the first tick confirms the worker has started
    sleep(55);
}

if ('waiting' !== $role) {
    fwrite(STDOUT, str_repeat('x', 4 * 1024 * 1024));
    fwrite(STDERR, str_repeat('y', 4 * 1024 * 1024));
    $fs->touch($directory.'/'.$role.'-output-finished');
}
