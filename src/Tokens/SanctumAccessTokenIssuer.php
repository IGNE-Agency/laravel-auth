<?php

declare(strict_types=1);

namespace Igne\Auth\Tokens;

use Carbon\CarbonInterface;
use Igne\Auth\Events\AccessTokenIssued;
use Igne\Auth\Events\AccessTokenRevoked;
use Igne\Auth\Tokens\Contracts\IssuesAccessTokens;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
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

        $issued = new NewAccessToken($token->plainTextToken, self::idOf($token->accessToken), $expiresAt);

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

        return $token === null ? null : self::idOf($token);
    }

    public function list(Authenticatable $user): Collection
    {
        return $this->tokensOf($this->tokenable($user))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (PersonalAccessToken $token): AccessToken => new AccessToken(
                id: self::idOf($token),
                name: $token->name,
                createdAt: $token->created_at,
                lastUsedAt: $token->last_used_at,
                expiresAt: $token->expires_at,
            ))
            ->values()
            ->toBase();
    }

    /**
     * Delete the token, which also fires its model events, and announce it.
     */
    private function revoke(Authenticatable $user, PersonalAccessToken $token, string $reason): void
    {
        $tokenId = self::idOf($token);

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
        if ($this->config->get('authentication.tokens.expires_in_minutes') === null) {
            return null;
        }

        return Date::now()->addMinutes($this->config->integer('authentication.tokens.expires_in_minutes'));
    }

    /**
     * @return Builder<PersonalAccessToken>
     */
    private function tokensOf(Model $tokenable): Builder
    {
        $model = Sanctum::personalAccessTokenModel();

        return $model::query()->whereMorphedTo('tokenable', $tokenable);
    }

    /**
     * Get the ID of a token, as the string the package passes around.
     */
    private static function idOf(PersonalAccessToken $token): string
    {
        $id = $token->getKey();

        if (! is_int($id) && ! is_string($id)) {
            throw new LogicException(sprintf('The access token model [%s] needs an integer or string key.', $token::class));
        }

        return (string) $id;
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
