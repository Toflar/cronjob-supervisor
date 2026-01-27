<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Provider;

use Toflar\CronjobSupervisor\Supervisor;

interface InitInterface
{
    public function init(Supervisor $supervisor): void;
}
