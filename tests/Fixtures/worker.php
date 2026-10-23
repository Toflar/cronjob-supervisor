<?php

declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Filesystem\Filesystem;

$directory = $argv[1] ?? '';
$identifier = $argv[2] ?? '';
$duration = (int) ($argv[3] ?? 0);
if ('' === $directory || '' === $identifier || $duration <= 0) {
    throw new InvalidArgumentException('Worker fixtures require a directory, identifier, and positive duration.');
}
$fs = new Filesystem();
$fs->mkdir($directory.'/workers');
$marker = $directory.'/workers/'.getmypid().'.json';
$fs->dumpFile($marker, json_encode(['identifier' => $identifier], JSON_THROW_ON_ERROR));

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
    $fs->remove($marker);
}
