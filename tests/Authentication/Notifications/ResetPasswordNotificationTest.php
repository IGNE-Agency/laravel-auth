<?php

declare(strict_types=1);

use Igne\Auth\Authentication\Notifications\ResetPasswordNotification;
use Igne\Auth\Tests\Fixtures\User;

it('links to the configured reset URL with the token and email filled in', function () {
    config(['authentication.passwords.reset_url' => 'https://app.example.com/reset-password?token={token}&email={email}']);
    $user = User::factory()->make(['email' => 'ada+test@example.com']);

    $mail = (new ResetPasswordNotification('reset-token'))->toMail($user);

    expect($mail->actionUrl)->toBe('https://app.example.com/reset-password?token=reset-token&email=ada%2Btest%40example.com');
});
