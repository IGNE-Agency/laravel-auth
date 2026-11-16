<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Fortify;

/**
 * A new password for the account in a password reset link. The strength comes
 * from the app's Password::defaults().
 */
final class ResetPasswordRequest extends FormRequest
{
    /**
     * The token from the reset link authorizes the request, in Fortify's
     * NewPasswordController.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|Password>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            Fortify::email() => ['required', 'string', 'email'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ];
    }
}
