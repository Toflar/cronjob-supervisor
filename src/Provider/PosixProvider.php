<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Provider;

class PosixProvider implements ProviderInterface
{
    public function isSupported(): bool
    {
        return \function_exists('posix_getpgid');
    }

    public function isPidRunning(int $pid): bool
    {
        // posix_getpgid returns false, if the process is not running anymore
        return false !== posix_getpgid($pid);
    }
}
