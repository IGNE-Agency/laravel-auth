<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The factor GET /user names as "confirmation_method": the password, a code of the authenticator app or an emailed code.
            'password' => ['required', 'string'],
        ];
    }
}
