<?php

declare(strict_types=1);

namespace Igne\Auth\Tests;

use Igne\Auth\AuthenticationServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [AuthenticationServiceProvider::class];
    }
}
