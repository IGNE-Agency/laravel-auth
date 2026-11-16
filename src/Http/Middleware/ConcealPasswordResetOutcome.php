<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Middleware;

use Closure;
use Igne\Auth\Authentication\Contracts\PasswordAuthenticatable;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every valid forgot-password request Fortify's success response, so the
 * response never reveals whether an account exists. Only users that can log in
 * with a password get a reset link, so a reset can't turn a passwordless user
 * into a password user.
 */
final class ConcealPasswordResetOutcome
{
    public function __construct(private readonly PasswordBrokerFactory $brokers) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $email = $this->email($request);

        if ($email === null) {
            return $next($request);
        }

        if (! $this->canResetPassword($email)) {
            return $this->concealedResponse($request);
        }

        $response = $next($request);

        // With valid input, Fortify only fails for an unknown or throttled user.
        return $response->getStatusCode() === Response::HTTP_UNPROCESSABLE_ENTITY
            ? $this->concealedResponse($request)
            : $response;
    }

    /**
     * Get the submitted email address, or null when it is invalid and Fortify
     * should report the validation error, by the rules of Fortify's
     * SendPasswordResetLinkRequest.
     */
    private function email(Request $request): ?string
    {
        $email = $request->input(Fortify::email());

        if (! is_string($email) || Validator::make($request->only(Fortify::email()), (new SendPasswordResetLinkRequest)->rules())->fails()) {
            return null;
        }

        return config('fortify.lowercase_usernames') ? Str::lower($email) : $email;
    }

    /**
     * Determine whether the account with the given email address may reset its
     * password.
     */
    private function canResetPassword(string $email): bool
    {
        $brokerName = config('fortify.passwords');
        $broker = $this->brokers->broker(is_string($brokerName) ? $brokerName : null);

        if (! $broker instanceof PasswordBroker) {
            return true;
        }

        $user = $broker->getUser([Fortify::email() => $email]);

        return $user instanceof PasswordAuthenticatable && $user->canAuthenticateWithPassword();
    }

    private function concealedResponse(Request $request): Response
    {
        /** @var SuccessfulPasswordResetLinkRequestResponse $response */
        $response = app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => Password::RESET_LINK_SENT]);

        return $response->toResponse($request);
    }
}
