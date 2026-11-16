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
    'two_factor_setup_required' => 'Set up two-factor authentication to continue.',
    'two_factor_required' => 'Confirm it is you with the code from your authenticator app to continue.',
    'current_session' => 'Log out to end the session you are using.',
    'two_factor_reset_mail' => [
        'subject' => 'Your two-factor authentication was reset',
        'reset_by' => ':name reset the two-factor authentication of your account on :date.',
        'reset' => 'The two-factor authentication of your account was reset on :date.',
        'next' => 'Log in again and set up two-factor authentication again.',
        'unexpected' => 'Did you not expect this? Then contact us right away.',
    ],
    'reset_two_factor_action' => [
        'name' => 'Reset two-factor authentication',
        'confirm' => 'This resets the two-factor authentication of the selected users. Their authenticator, recovery codes and trusted devices are removed, they are logged out on every device (sessions and access tokens) and they get an email about the reset. They set up two-factor authentication again when they next log in. Resetting your own logs you out as well.',
        'button' => 'Reset two-factor authentication',
        'password' => 'Your password',
        'password_help' => 'Enter your own password to confirm this action.',
        'done' => 'The two-factor authentication was reset.',
    ],
];
