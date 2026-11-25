<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Requests;

use Laravel\Fortify\Http\Requests\LoginRequest as FortifyLoginRequest;

/**
 * The login form. Fortify's own request, with the inputs this package adds.
 */
final class LoginRequest extends FortifyLoginRequest
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
            // Session mode: keep the user logged in after the browser closes.
            'remember' => ['sometimes', 'boolean'],
            // Token mode: the name of the access token; the user agent by default.
            'device_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
