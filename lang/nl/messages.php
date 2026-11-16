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
    'two_factor_setup_required' => 'Stel tweestapsverificatie in om verder te gaan.',
    'two_factor_required' => 'Bevestig met de code uit je authenticator-app dat jij het bent om verder te gaan.',
    'current_session' => 'Log uit om de sessie te beëindigen die je nu gebruikt.',
    'two_factor_reset_mail' => [
        'subject' => 'Je tweestapsverificatie is gereset',
        'reset_by' => ':name heeft op :date de tweestapsverificatie van je account gereset.',
        'reset' => 'De tweestapsverificatie van je account is op :date gereset.',
        'next' => 'Log opnieuw in en stel je tweestapsverificatie opnieuw in.',
        'unexpected' => 'Had je dit niet verwacht? Neem dan direct contact met ons op.',
    ],
    'reset_two_factor_action' => [
        'name' => 'Tweestapsverificatie resetten',
        'confirm' => 'De tweestapsverificatie van de geselecteerde gebruikers resetten? Ze worden overal uitgelogd en stellen bij het inloggen opnieuw tweestapsverificatie in.',
        'button' => 'Resetten',
        'done' => 'De tweestapsverificatie is gereset.',
    ],
];
