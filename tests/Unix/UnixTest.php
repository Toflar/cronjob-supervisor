<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Test\Unix;

use Toflar\CronjobSupervisor\Provider\PsProvider;
use Toflar\CronjobSupervisor\Test\AbstractProviderTestCase;

class UnixTest extends AbstractProviderTestCase
{
    public static function provideProviders(): array
    {
        return [
            'ps' => [PsProvider::class],
        ];
    }
}
