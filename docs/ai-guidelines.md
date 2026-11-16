# AI guidelines

The package ships a [Laravel Boost](https://github.com/laravel/boost) guideline (`resources/boost/guidelines/core.blade.php`: the concepts, naming, layers and package rules) and the `authentication-development` skill (`resources/boost/skills/authentication-development`). Boost reads them from the installed package, when the app requires it directly. Boost only adds a newly installed package's guideline when you choose it: run `php artisan boost:update` (or `boost:install`) interactively and select `igne-agency/laravel-auth`. After that, `boost:update --no-interaction` keeps it up to date.

Change the guideline, the skill and [Concepts](concepts.md) in the same pull request as the behaviour they describe.
