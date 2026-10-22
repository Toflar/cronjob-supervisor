<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Test\Windows;

use Toflar\CronjobSupervisor\Provider\FlockProvider;
use Toflar\CronjobSupervisor\Provider\WindowsTaskListProvider;
use Toflar\CronjobSupervisor\Test\AbstractProviderTestCase;

final class WindowsTest extends AbstractProviderTestCase
{
    public static function provideProviders(): \Iterator
    {
        yield 'tasklist' => [WindowsTaskListProvider::class];
        yield 'flock' => [FlockProvider::class];
    }
}
