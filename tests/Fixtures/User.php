<?php

declare(strict_types=1);

namespace Igne\Auth\Tests\Fixtures;

use Igne\Auth\Authentication\AuthenticatesWithPassword;
use Igne\Auth\Authentication\Contracts\PasswordAuthenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $email
 * @property string|null $password
 */
final class User extends Authenticatable implements PasswordAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use AuthenticatesWithPassword, HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
