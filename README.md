# igne-agency/laravel-auth

Headless authentication for Laravel apps on Laravel Fortify and Sanctum: password and emailed-code login, per-device two-factor authentication, sessions and an audit log, documented in the app's OpenAPI spec.

## Requirements

- PHP 8.4
- Laravel 13
- laravel/fortify 1.42 or later, laravel/sanctum 4.4 or later

## Install

```bash
composer require igne-agency/laravel-auth
php artisan vendor:publish --tag=authentication-config
```
