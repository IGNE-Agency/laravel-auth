<?php

declare(strict_types=1);

namespace Igne\Auth\Tests\Fixtures;

use Igne\Auth\Authentication\AuthenticatesWithPassword;
use Igne\Auth\Authentication\Contracts\PasswordAuthenticatable;
use Igne\Auth\LoginCodes\AuthenticatesWithLoginCode;
use Igne\Auth\LoginCodes\Contracts\LoginCodeAuthenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $email
 * @property string|null $password
 * @property bool $login_codes_enabled
 */
final class User extends Authenticatable implements LoginCodeAuthenticatable, PasswordAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use AuthenticatesWithLoginCode, AuthenticatesWithPassword, HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['login_codes_enabled' => 'boolean'];

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
