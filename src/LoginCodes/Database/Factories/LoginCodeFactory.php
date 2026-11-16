<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes\Database\Factories;

use Igne\Auth\LoginCodes\LoginCodePurpose;
use Igne\Auth\LoginCodes\Models\LoginCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * Makes login codes. Give the user with ->for($user, 'authenticatable').
 *
 * @extends Factory<LoginCode>
 */
final class LoginCodeFactory extends Factory
{
    protected $model = LoginCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purpose' => LoginCodePurpose::Login,
            'code_hash' => LoginCode::hashFor(str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)),
            'attempts' => 0,
            'expires_at' => Date::now()->addMinutes(10),
        ];
    }

    /**
     * A code whose plain text is known.
     */
    public function withCode(string $code): static
    {
        return $this->state(['code_hash' => LoginCode::hashFor($code)]);
    }

    /**
     * A code to confirm the identity of a logged-in user.
     */
    public function forConfirmation(): static
    {
        return $this->state(['purpose' => LoginCodePurpose::Confirm]);
    }

    /**
     * A code whose lifetime has passed.
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => Date::now()->subSecond()]);
    }

    /**
     * A code that was used.
     */
    public function consumed(): static
    {
        return $this->state(['consumed_at' => Date::now()]);
    }
}
