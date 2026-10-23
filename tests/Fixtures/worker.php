<?php

declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

$directory = $argv[1] ?? '';
$identifier = $argv[2] ?? '';
$duration = (int) ($argv[3] ?? 0);
if ('' === $directory || '' === $identifier || $duration <= 0) {
    throw new InvalidArgumentException('Worker fixtures require a directory, identifier, and positive duration.');
}
$fs = new Filesystem();
$fs->mkdir($directory.'/workers');
$marker = $directory.'/workers/'.getmypid().'.json';
$lock = (new LockFactory(new FlockStore($directory)))->createLock('worker-markers');
$lock->acquire(true);

try {
    $fs->dumpFile($marker, json_encode(['identifier' => $identifier, 'runner' => getenv('CRONJOB_TEST_RUNNER_PID'), 'started' => microtime(true)], JSON_THROW_ON_ERROR));
} finally {
    $lock->release();
}

try {
    // Keep the initial workers alive until every configured slot has been observed by the test
    while (!$fs->exists($directory.'/release') && !$fs->exists($directory.'/stop')) {
        usleep(10000);
    }

    $end = hrtime(true) + $duration * 1000000000;

    while (hrtime(true) < $end && !$fs->exists($directory.'/stop')) {
        usleep(50000);
    }
} finally {
    $lock->acquire(true);

    try {
        $fs->remove($marker);
    } finally {
        $lock->release();
    }
}
