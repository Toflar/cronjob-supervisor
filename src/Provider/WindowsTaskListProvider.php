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
            $process = new Process(['tasklist', '/FI', 'PID eq 0']);
            $process->mustRun();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isPidRunning(int $pid): bool
    {
        try {
            $process = new Process(['tasklist', '/FI', "PID eq $pid", '/FO', 'CSV', '/NH']);
            $process->mustRun();

            // Symfony Process can track cmd.exe, while other callers may track the executable directly
            foreach (explode("\n", trim($process->getOutput())) as $line) {
                $columns = str_getcsv(trim($line), ',', '"', '');
                if (isset($columns[1]) && (string) $pid === $columns[1]) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }
}
