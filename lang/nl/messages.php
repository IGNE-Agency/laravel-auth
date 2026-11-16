<?php

declare(strict_types=1);

return [
    'password_unavailable' => 'Je account gebruikt geen wachtwoord.',
    'login_code_sent' => 'Als het account met een code kan inloggen, hebben we er een gemaild.',
    'confirmation_code_sent' => 'We hebben je een bevestigingscode gemaild.',
    'login_code_unavailable' => 'Je account bevestigt niet met een gemailde code.',
    'login_code_mail' => [
        'subject' => 'Je inlogcode',
        'introduction' => 'Gebruik deze code om in te loggen.',
    ],
    'confirmation_code_mail' => [
        'subject' => 'Je bevestigingscode',
        'introduction' => 'Gebruik deze code om te bevestigen dat jij het bent.',
    ],
    'code_mail' => [
        'code' => 'Je code: :code',
        'action' => 'Inloggen',
        'expiry' => 'De code verloopt over :minutes minuten en werkt één keer.',
        'ignore' => 'Heb je niet om deze code gevraagd? Dan kun je deze e-mail negeren.',
    ],
];
