# Development

The package runs on its own, on Orchestra Testbench with Fortify and Sanctum:

```bash
composer install
composer lint      # Pint
composer analyse   # Larastan, level max on src and tests/Fixtures, without the Nova code
composer test      # Pest
```

`tests/TestCase.php` configures the app the suite needs: an in-memory SQLite database, array cache, session and mail, and the fixture user in `tests/Fixtures`.

## CI

CI (`.github/workflows/ci.yml`) runs two jobs on pull requests and pushes to `main`:

- `checks`: Pint, Larastan and Pest, on the lowest and the newest dependencies the constraints allow;
- `nova`: Larastan with `phpstan-nova.neon.dist` and the tests in `tests/Nova`, with Nova installed through the `COMPOSER_AUTH` secret (skipped without it).

## Run the Nova checks locally

Without Nova, the tests in `tests/Nova` are skipped. With Nova credentials in your Composer auth, run the Nova checks locally, then revert `composer.json`:

```bash
composer config repositories.nova composer https://nova.laravel.com
composer require --dev laravel/nova:^5.11
vendor/bin/phpstan analyse -c phpstan-nova.neon.dist --memory-limit=1G
vendor/bin/pest tests/Nova
```
