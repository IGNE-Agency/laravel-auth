<?php

declare(strict_types=1);

use Igne\Auth\Tests\Support\InteractsWithNova;
use Igne\Auth\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);
pest()->use(InteractsWithNova::class)->in('Nova');
