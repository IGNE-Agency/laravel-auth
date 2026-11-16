<?php

declare(strict_types=1);

use Igne\Auth\LoginCodes\LoginCodePurpose;
use Igne\Auth\LoginCodes\Notifications\LoginCodeNotification;
use Igne\Auth\Tests\Fixtures\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * @return list<string>
 */
function mailLines(MailMessage $mail): array
{
    return array_map(strval(...), [...$mail->introLines, ...$mail->outroLines]);
}

it('mails the login code with its expiry and a link to log in', function (): void {
    $this->freezeTime();
    $notification = new LoginCodeNotification('123456', now()->addMinutes(10), 'https://app.example.com/login?code=123456');

    $mail = $notification->toMail(User::factory()->make());

    expect($mail->subject)->toBe('Your login code')
        ->and($mail->actionText)->toBe('Log in')
        ->and($mail->actionUrl)->toBe('https://app.example.com/login?code=123456')
        ->and(mailLines($mail))->toBe([
            'Use this code to log in.',
            'Your code: 123456',
            'The code expires in 10 minutes and works once.',
            'Did you not request this code? Then you can ignore this email.',
        ]);
});

it('mails only the code when there is no link', function (): void {
    $notification = new LoginCodeNotification('123456', now()->addMinutes(10));

    $mail = $notification->toMail(User::factory()->make());

    expect($mail->actionUrl)->toBeNull()
        ->and(mailLines($mail))->toContain('Your code: 123456');
});

it('mails a confirmation code under its own subject', function (): void {
    $notification = new LoginCodeNotification('123456', now()->addMinutes(10), purpose: LoginCodePurpose::Confirm);

    $mail = $notification->toMail(User::factory()->make());

    expect($mail->subject)->toBe('Your confirmation code')
        ->and(mailLines($mail)[0])->toBe('Use this code to confirm it is you.');
});

it('counts the minutes left when the mail is sent', function (): void {
    $this->freezeTime();
    $notification = new LoginCodeNotification('123456', now()->addMinutes(10));
    $this->travel(4)->minutes();

    $mail = $notification->toMail(User::factory()->make());

    expect(mailLines($mail))->toContain('The code expires in 6 minutes and works once.');
});
