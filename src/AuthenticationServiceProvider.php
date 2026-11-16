<?php

declare(strict_types=1);

namespace Igne\Auth;

use Illuminate\Support\ServiceProvider;

final class AuthenticationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::path('config/authentication.php'), 'authentication');
    }

    public function boot(): void
    {
        $this->configurePublishing();
    }

    private function configurePublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            self::path('config/authentication.php') => $this->app->configPath('authentication.php'),
        ], 'authentication-config');
    }

    private static function path(string $path): string
    {
        return dirname(__DIR__).'/'.$path;
    }
}
