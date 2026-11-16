<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes\Actions;

use Igne\Auth\LoginCodes\ConsumeLoginCodeResult;
use Igne\Auth\LoginCodes\Contracts\LoginCodeAuthenticatable;
use Igne\Auth\LoginCodes\LoginCodePurpose;
use Igne\Auth\LoginCodes\Models\LoginCode;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Date;
use SensitiveParameter;

/**
 * Checks an emailed code and uses it up. Swap it in
 * "authentication.login_codes.actions.consume_login_code".
 *
 * Only the user's newest unused code for the purpose counts. A code takes a
 * limited number of wrong attempts; on top of that, the user's failed attempts
 * across codes are rate limited, and a new code does not reset that limit.
 */
class ConsumeLoginCodeAction
{
    public function __construct(
        private readonly Repository $config,
        private readonly RateLimiter $limiter,
    ) {}

    public function execute(LoginCodeAuthenticatable $user, LoginCodePurpose $purpose, #[SensitiveParameter] string $code): ConsumeLoginCodeResult
    {
        $limiterKey = $this->limiterKey($user);

        if ($this->limiter->tooManyAttempts($limiterKey, $this->maxFailuresPerUser())) {
            return ConsumeLoginCodeResult::RateLimited;
        }

        $loginCode = $this->newestUnusedCode($user, $purpose);

        if ($loginCode === null) {
            return ConsumeLoginCodeResult::NotFound;
        }

        if ($loginCode->attempts >= $this->maxAttemptsPerCode()) {
            return ConsumeLoginCodeResult::TooManyAttempts;
        }

        if (! $loginCode->expires_at->isFuture()) {
            return ConsumeLoginCodeResult::Expired;
        }

        if (! $loginCode->matches($code)) {
            $this->recordFailure($loginCode, $limiterKey);

            return ConsumeLoginCodeResult::Incorrect;
        }

        if (! $this->markConsumed($loginCode)) {
            return ConsumeLoginCodeResult::NotFound;
        }

        $this->limiter->clear($limiterKey);

        return ConsumeLoginCodeResult::Ok;
    }

    private function newestUnusedCode(LoginCodeAuthenticatable $user, LoginCodePurpose $purpose): ?LoginCode
    {
        $relation = $user->loginCodes();

        return $relation
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->orderByDesc('created_at')
            ->orderByDesc($relation->getRelated()->getKeyName())
            ->first();
    }

    /**
     * Use the code up, unless another request did so first or it lapsed in the
     * meantime. The condition makes concurrent requests succeed only once.
     */
    private function markConsumed(LoginCode $loginCode): bool
    {
        $now = Date::now();

        $updated = $loginCode->newQuery()
            ->whereKey($loginCode->getKey())
            ->whereNull('consumed_at')
            ->where('attempts', '<', $this->maxAttemptsPerCode())
            ->where('expires_at', '>', $now)
            ->update(['consumed_at' => $now]);

        return $updated === 1;
    }

    /**
     * Count a wrong code against the code and against the user. The code
     * stops working at its attempt cap.
     */
    private function recordFailure(LoginCode $loginCode, string $limiterKey): void
    {
        $loginCode->newQuery()->whereKey($loginCode->getKey())->increment('attempts');

        $loginCode->newQuery()
            ->whereKey($loginCode->getKey())
            ->where('attempts', '>=', $this->maxAttemptsPerCode())
            ->update(['expires_at' => Date::now()]);

        $this->limiter->hit($limiterKey, (int) $this->config->get('authentication.login_codes.rate_limit_attempts.time_window_in_seconds', 3600));
    }

    private function limiterKey(LoginCodeAuthenticatable $user): string
    {
        return sprintf(
            'authentication:login-code-failures:%s:%s',
            $user->loginCodes()->getMorphClass(),
            (string) $user->getAuthIdentifier(),
        );
    }

    private function maxAttemptsPerCode(): int
    {
        return (int) $this->config->get('authentication.login_codes.max_attempts_per_code', 5);
    }

    private function maxFailuresPerUser(): int
    {
        return (int) $this->config->get('authentication.login_codes.rate_limit_attempts.max_attempts_per_user', 20);
    }
}
