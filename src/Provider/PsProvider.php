<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Provider;

use Symfony\Component\Process\Process;

class PsProvider implements ProviderInterface
{
    public function isSupported(): bool
    {
        try {
            $process = new Process(['ps']);
            $process->mustRun();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isPidRunning(int $pid): bool
    {
        try {
            $process = new Process(['ps', '-p', $pid]);
            $process->mustRun();

            // Check for defunct output. If the process was started within this very process,
            // it will still be listed, although it's actually finished.
            if (str_contains($process->getOutput(), '<defunct>')) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
