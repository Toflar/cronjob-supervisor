<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Provider;

interface ProviderInterface
{
    public function isSupported(): bool;

    public function isPidRunning(int $pid): bool;
}
