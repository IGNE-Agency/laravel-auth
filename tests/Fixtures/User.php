<?php

declare(strict_types=1);

namespace Igne\Auth\Tests\Fixtures;

use Carbon\CarbonInterface;
use Igne\Auth\Authentication\AuthenticatesWithPassword;
use Igne\Auth\Authentication\Contracts\PasswordAuthenticatable;
use Igne\Auth\LoginCodes\AuthenticatesWithLoginCode;
use Igne\Auth\LoginCodes\Contracts\LoginCodeAuthenticatable;
use Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable;
use Igne\Auth\TwoFactor\InteractsWithTwoFactorAuthentication;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $email
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonInterface|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property bool $login_codes_enabled
 */
final class User extends Authenticatable implements LoginCodeAuthenticatable, PasswordAuthenticatable, TwoFactorAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use AuthenticatesWithLoginCode, AuthenticatesWithPassword, HasApiTokens, HasFactory, InteractsWithTwoFactorAuthentication, Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'];

    protected $casts = [
        'login_codes_enabled' => 'boolean',
        'two_factor_confirmed_at' => 'datetime',
    ];

    /**
     * Users can log in with codes unless the fixture switched them off.
     */
    public function canAuthenticateWithLoginCode(): bool
    {
        return $this->getAttribute('login_codes_enabled') !== false;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
