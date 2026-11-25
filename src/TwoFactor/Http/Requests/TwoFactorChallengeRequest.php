<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Http\Requests;

use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/**
 * The two-factor challenge. Fortify's own request, with the inputs this package adds.
 */
final class TwoFactorChallengeRequest extends TwoFactorLoginRequest
{
    /**
     * The credentials prove the request, in Fortify.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // Trust this device for longer than the session or the access token.
            'remember_device' => ['sometimes', 'boolean'],
            // Token mode: the name of the access token; the user agent by default.
            'device_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
