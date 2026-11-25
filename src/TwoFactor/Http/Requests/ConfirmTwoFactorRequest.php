<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmTwoFactorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A code of the authenticator app.
            'code' => ['required', 'string'],
        ];
    }
}
