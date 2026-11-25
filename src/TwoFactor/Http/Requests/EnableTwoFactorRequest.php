<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EnableTwoFactorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Replace a secret that already exists.
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
