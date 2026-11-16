<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * A new password for the authenticated account, with its current one. The
 * current password is checked on the "sanctum" guard that the route
 * authenticates with, so it works for cookie and bearer-token clients alike.
 * The strength comes from the app's Password::defaults().
 */
final class UpdatePasswordRequest extends FormRequest
{
    /**
     * The password belongs to the authenticated account itself, so there is
     * nothing to authorize.
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
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ];
    }
}
