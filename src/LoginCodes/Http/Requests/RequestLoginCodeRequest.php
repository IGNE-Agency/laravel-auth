<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * A request for a login code, by the username Fortify logs in with.
 */
final class RequestLoginCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            Fortify::username() => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * Get the validated username.
     */
    public function username(): string
    {
        return $this->string(Fortify::username())->toString();
    }

    /**
     * Lowercase the username like Fortify's login does, when Fortify is
     * configured to.
     */
    protected function prepareForValidation(): void
    {
        $username = $this->input(Fortify::username());

        if (config('fortify.lowercase_usernames') && is_string($username)) {
            $this->merge([Fortify::username() => Str::lower($username)]);
        }
    }
}
