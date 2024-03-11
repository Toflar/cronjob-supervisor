<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Provider;

use Symfony\Component\Process\Process;

class WindowsTaskListProvider implements ProviderInterface
{
    public function isSupported(): bool
    {
        if ('\\' !== \DIRECTORY_SEPARATOR) {
            return false;
        }

        try {
            $process = new Process(['tasklist']);
            $process->mustRun();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isPidRunning(int $pid): bool
    {
        try {
            $process = new Process(['tasklist', '/FI', "PID eq $pid"]);
            $process->mustRun();

            // Symfony Process starts Windows processes via cmd.exe
            return str_contains($process->getOutput(), 'cmd.exe');
        } catch (\Throwable) {
            return false;
        }
    }
}
