# Development

The package runs on its own, on Orchestra Testbench with Fortify, Sanctum and Scramble:

```bash
composer install
composer lint      # Pint
composer analyse   # Larastan, level max on src and tests/Fixtures, without the Nova code
composer test      # Pest
```

`tests/TestCase.php` configures the app the suite needs: an in-memory SQLite database, array cache, session and mail, and the fixture user in `tests/Fixtures`. Without Scramble or `opis/json-schema` installed, the snapshot test is skipped and `assertMatchesOpenApi` asserts nothing; CI runs the suite that way too. [OpenAPI](openapi.md#in-this-package) explains the snapshot and the assertion.

## CI

CI (`.github/workflows/ci.yml`) runs three jobs on pull requests and pushes to `main`:

- `checks`: Pint, Larastan and Pest, on the lowest and the newest dependencies the constraints allow;
- `without-scramble`: Pest after removing Scramble and `opis/json-schema`, so the package also works without them;
- `nova`: Larastan with `phpstan-nova.neon.dist` and the tests in `tests/Nova`, with Nova installed through the `COMPOSER_AUTH` secret (skipped without it).

## Run the Nova checks locally

Without Nova, the tests in `tests/Nova` are skipped. With Nova credentials in your Composer auth, run the Nova checks locally, then revert `composer.json`:

```bash
composer config repositories.nova composer https://nova.laravel.com
composer require --dev laravel/nova:^5.11
vendor/bin/phpstan analyse -c phpstan-nova.neon.dist --memory-limit=1G
vendor/bin/pest tests/Nova
```
