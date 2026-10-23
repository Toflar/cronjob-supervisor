<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Test\Unix;

use Toflar\CronjobSupervisor\Provider\FlockProvider;
use Toflar\CronjobSupervisor\Provider\PsProvider;
use Toflar\CronjobSupervisor\Test\AbstractProviderTestCase;

final class UnixTest extends AbstractProviderTestCase
{
    public static function provideProviders(): \Iterator
    {
        yield 'ps' => [PsProvider::class];
        yield 'flock' => [FlockProvider::class];
    }
}
