<?php

declare(strict_types=1);

namespace Igne\Auth\Tokens;

use Carbon\CarbonInterface;
use Igne\Auth\Events\AccessTokenIssued;
use Igne\Auth\Events\AccessTokenRevoked;
use Igne\Auth\Tokens\Contracts\IssuesAccessTokens;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken as SanctumNewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use LogicException;

/**
 * Issues Sanctum personal access tokens. The user model must use Sanctum's
 * HasApiTokens trait; the package never adds it.
 */
class SanctumAccessTokenIssuer implements IssuesAccessTokens
{
    public function __construct(
        private readonly Repository $config,
        private readonly Dispatcher $events,
    ) {}

    public function issue(Authenticatable $user, string $name): NewAccessToken
    {
        $tokenable = $this->tokenable($user);
        $expiresAt = $this->expiresAt();

        $token = method_exists($tokenable, 'createToken') ? $tokenable->createToken($name, ['*'], $expiresAt) : null;

        if (! $token instanceof SanctumNewAccessToken) {
            throw new LogicException('Sanctum did not issue an access token.');
        }

        $issued = new NewAccessToken($token->plainTextToken, (string) $token->accessToken->getKey(), $expiresAt);

        $this->events->dispatch(new AccessTokenIssued($user, $issued->id, $name, $expiresAt));

        return $issued;
    }

    public function revokeCurrent(Authenticatable $user, string $reason = 'logout'): void
    {
        $token = $this->currentToken($user);

        if ($token === null) {
            return;
        }

        $this->revoke($user, $token, $reason);
    }

    public function revokeAll(Authenticatable $user, string $reason = 'revoked'): void
    {
        $this->tokensOf($this->tokenable($user))
            ->get()
            ->each(fn (PersonalAccessToken $token) => $this->revoke($user, $token, $reason));
    }

    public function currentTokenId(Authenticatable $user): ?string
    {
        $token = $this->currentToken($user);

        return $token === null ? null : (string) $token->getKey();
    }

    public function list(Authenticatable $user): Collection
    {
        return $this->tokensOf($this->tokenable($user))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (PersonalAccessToken $token): AccessToken => new AccessToken(
                id: (string) $token->getKey(),
                name: (string) $token->getAttribute('name'),
                createdAt: $token->getAttribute('created_at'),
                lastUsedAt: $token->getAttribute('last_used_at'),
                expiresAt: $token->getAttribute('expires_at'),
            ))
            ->values()
            ->toBase();
    }

    /**
     * Delete the token, which also fires its model events, and announce it.
     */
    private function revoke(Authenticatable $user, PersonalAccessToken $token, string $reason): void
    {
        $tokenId = (string) $token->getKey();

        $token->delete();

        $this->events->dispatch(new AccessTokenRevoked($user, $tokenId, $reason));
    }

    /**
     * Get the personal access token the current request was authenticated
     * with. Sanctum gives a TransientToken to session requests, which is not
     * a revocable token.
     */
    private function currentToken(Authenticatable $user): ?PersonalAccessToken
    {
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken ? $token : null;
    }

    private function expiresAt(): ?CarbonInterface
    {
        $minutes = $this->config->get('authentication.tokens.expires_in_minutes');

        return $minutes === null ? null : Date::now()->addMinutes((int) $minutes);
    }

    /**
     * @return Builder<PersonalAccessToken>
     */
    private function tokensOf(Model $tokenable): Builder
    {
        /** @var class-string<PersonalAccessToken> $model */
        $model = Sanctum::personalAccessTokenModel();

        return $model::query()->whereMorphedTo('tokenable', $tokenable);
    }

    /**
     * Ensure the user can hold Sanctum tokens.
     */
    private function tokenable(Authenticatable $user): Model
    {
        if (! $user instanceof Model || ! in_array(HasApiTokens::class, class_uses_recursive($user), true)) {
            throw new LogicException(sprintf('[%s] must be a model that uses [%s] to receive access tokens.', $user::class, HasApiTokens::class));
        }

        return $user;
    }
}
