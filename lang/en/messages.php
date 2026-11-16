<?php

declare(strict_types=1);

return [
    'password_unavailable' => 'Your account does not use a password.',
    'login_code_sent' => 'If the account can log in with a code, we have emailed one.',
    'confirmation_code_sent' => 'We have emailed you a confirmation code.',
    'login_code_unavailable' => 'Your account does not confirm with an emailed code.',
    'login_code_mail' => [
        'subject' => 'Your login code',
        'introduction' => 'Use this code to log in.',
    ],
    'confirmation_code_mail' => [
        'subject' => 'Your confirmation code',
        'introduction' => 'Use this code to confirm it is you.',
    ],
    'code_mail' => [
        'code' => 'Your code: :code',
        'action' => 'Log in',
        'expiry' => 'The code expires in :minutes minutes and works once.',
        'ignore' => 'Did you not request this code? Then you can ignore this email.',
    ],
];
