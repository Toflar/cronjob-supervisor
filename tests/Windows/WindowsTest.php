<?php

declare(strict_types=1);

namespace Toflar\CronjobSupervisor\Test\Windows;

use Toflar\CronjobSupervisor\Provider\WindowsTaskListProvider;
use Toflar\CronjobSupervisor\Test\AbstractProviderTestCase;

class WindowsTest extends AbstractProviderTestCase
{
    public static function provideProviders(): array
    {
        return [
            'tasklist' => [WindowsTaskListProvider::class],
        ];
    }
}
