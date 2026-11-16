<?php

declare(strict_types=1);

namespace Igne\Auth\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'login_codes_enabled' => true,
        ];
    }

    /**
     * A user that can't log in with a password.
     */
    public function withoutPassword(): static
    {
        return $this->state(['password' => null]);
    }

    /**
     * A user that can't log in with an emailed code.
     */
    public function withoutLoginCodes(): static
    {
        return $this->state(['login_codes_enabled' => false]);
    }

    /**
     * A user that set up and confirmed two-factor authentication.
     */
    public function withTwoFactorAuthentication(): static
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt((new Google2FA)->generateSecretKey()),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt((string) json_encode(
                Collection::times(8, static fn (): string => RecoveryCode::generate())->all(),
            )),
            'two_factor_confirmed_at' => Date::now(),
        ]);
    }
}
