<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Provider;

use Symfony\Component\Process\Process;

class PsProvider implements BatchProviderInterface
{
    public function isSupported(): bool
    {
        // Not supported on Windows (cygwin could cause false-positives here)
        if ('\\' === \DIRECTORY_SEPARATOR) {
            return false;
        }

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
            $process = new Process(['ps', '-p', (string) $pid]);
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

    public function getRunningPids(array $pids): array|null
    {
        $processes = [];

        foreach (array_chunk(array_unique($pids), 256) as $batch) {
            $running = $this->queryBatch($batch);
            if (null === $running) {
                return null;
            }

            array_push($processes, ...$running);
        }

        return $processes;
    }

    /**
     * @param list<string> $command
     */
    protected function createProcess(array $command): Process
    {
        return new Process($command, env: ['LC_ALL' => 'C']);
    }

    /**
     * @param list<int> $pids
     *
     * @return list<int>|null
     */
    private function queryBatch(array $pids): array|null
    {
        try {
            $process = $this->createProcess(['ps', '-p', implode(',', $pids), '-o', 'pid=', '-o', 'stat=']);
            $exitCode = $process->run();
            $output = trim($process->getOutput());
            $error = trim($process->getErrorOutput());
        } catch (\Throwable) {
            return null;
        }

        if (1 === $exitCode && '' === $output && '' === $error) {
            return [];
        }

        return 0 === $exitCode && '' === $error ? $this->parseOutput($output, $pids) : null;
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

            if (!preg_match('/^\s*(\d+)\s+(\S+)\s*$/', $line, $matches)) {
                return null;
            }

            $pid = (int) $matches[1];
            // Check the process state because completed children may still be listed as zombies
            if (isset($requested[$pid]) && !str_starts_with($matches[2], 'Z')) {
                $processes[] = $pid;
            }
        }

        return $processes;
    }
}
