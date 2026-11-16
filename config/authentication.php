<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The package registers its own JSON routes and points them at Fortify's
    | controllers. Every route also gets the AcceptJson middleware, so Fortify
    | always answers with its JSON responses.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'api',
        'middleware' => ['api'],
    ],

];
