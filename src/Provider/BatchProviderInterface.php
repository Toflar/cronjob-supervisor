<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Provider;

interface BatchProviderInterface extends ProviderInterface
{
    /**
     * @param list<int> $pids
     *
     * @return list<int>|null Running PIDs, or null if the query failed
     */
    public function getRunningPids(array $pids): array|null;
}
