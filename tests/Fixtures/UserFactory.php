<?php

declare(strict_types=1);

namespace Igne\Auth\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

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
}
