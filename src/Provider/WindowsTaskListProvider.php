<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Provider;

use Symfony\Component\Process\Process;

class WindowsTaskListProvider implements BatchProviderInterface
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

    public function getRunningPids(array $pids): array|null
    {
        if ([] === $pids) {
            return [];
        }

        try {
            $process = $this->createProcess(['tasklist', '/FO', 'CSV', '/NH']);
            $process->mustRun();

            return '' === trim($process->getErrorOutput()) ? $this->parseOutput($process->getOutput(), $pids) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $command
     */
    protected function createProcess(array $command): Process
    {
        return new Process($command);
    }

    /**
     * @param list<int> $pids
     *
     * @return list<int>|null
     */
    private function parseOutput(string $output, array $pids): array|null
    {
        if ('' === trim($output)) {
            return null;
        }

        $requested = array_fill_keys($pids, true);
        $processes = [];

        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            if ('' === $line) {
                continue;
            }

            $columns = str_getcsv($line, ',', '"', '');
            if (\count($columns) < 2 || !\is_string($columns[1]) || !preg_match('/^\d+$/D', trim($columns[1]))) {
                return null;
            }

            $pid = (int) trim($columns[1]);
            // Symfony Process may track cmd.exe, but matching the PID also supports other executable names
            if (isset($requested[$pid])) {
                $processes[] = $pid;
            }
        }

        return $processes;
    }
}
