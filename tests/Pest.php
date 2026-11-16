<?php

declare(strict_types=1);

use Igne\Auth\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Binds the Testbench test case when the package's tests run on their own.
| Inside an app, the app's tests/Pest.php binds it to this directory.
|
*/

pest()->extend(TestCase::class)->in(__DIR__);
