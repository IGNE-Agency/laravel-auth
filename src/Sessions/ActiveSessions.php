<?php

declare(strict_types=1);

namespace Igne\Auth\Sessions;

use Igne\Auth\Authentication\UserProviderResolver;
use Igne\Auth\Events\SessionRevoked;
use Igne\Auth\Http\Channel;
use Igne\Auth\Http\DeviceName;
use Igne\Auth\Http\Exceptions\CurrentSessionException;
use Igne\Auth\Tokens\AccessToken;
use Igne\Auth\Tokens\Contracts\IssuesAccessTokens;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use LogicException;
use stdClass;

/**
 * The places a user is logged in, and ending them: web sessions from the
 * session table (only with the "database" session driver), plus access
 * tokens from the token issuer.
 *
 * Revoking a web session also cycles the user's remember token, so a browser
 * that ticked "remember me" can't log back in with its cookie.
 */
final class ActiveSessions
{
    public const string SESSION_PREFIX = 'session:';

    public const string TOKEN_PREFIX = 'token:';

    public function __construct(
        private readonly Repository $config,
        private readonly DatabaseManager $database,
        private readonly IssuesAccessTokens $tokens,
        private readonly UserProviderResolver $users,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Get the hash that identifies a web session: the SHA-256 of its id.
     */
    public static function hashSessionId(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    /**
     * Get the hash of the cookie session the request runs in, if any. A
     * token-mode request's header session is not a web session.
     */
    public static function currentSessionHash(Request $request): ?string
    {
        if (! $request->hasSession() || HeaderSessionFactory::isHeaderSession($request->session())) {
            return null;
        }

        return self::hashSessionId($request->session()->getId());
    }

    /**
     * List the user's active web sessions and access tokens: the one the
     * request comes from first, then the most recently active.
     *
     * @return Collection<int, ActiveSession>
     */
    public function of(Request $request, Authenticatable $user): Collection
    {
        $currentSessionHash = self::currentSessionHash($request);
        $currentTokenId = $this->tokens->currentTokenId($user);

        $webSessions = $this->webSessions($user)->map(static function (stdClass $row) use ($currentSessionHash): ActiveSession {
            $hash = self::hashSessionId(self::sessionIdOf($row));
            $ipAddress = data_get($row, 'ip_address');
            $userAgent = data_get($row, 'user_agent');
            $lastActivity = data_get($row, 'last_activity');

            return new ActiveSession(
                id: self::SESSION_PREFIX.$hash,
                type: Channel::Session,
                name: DeviceName::fromUserAgent(is_string($userAgent) ? $userAgent : null),
                ipAddress: is_string($ipAddress) ? $ipAddress : null,
                lastActiveAt: is_numeric($lastActivity) ? Date::createFromTimestamp($lastActivity) : null,
                isCurrent: $hash === $currentSessionHash,
            );
        });

        $tokens = $this->activeTokens($user)->map(static fn (AccessToken $token): ActiveSession => new ActiveSession(
            id: self::TOKEN_PREFIX.$token->id,
            type: Channel::Token,
            name: $token->name,
            ipAddress: null,
            lastActiveAt: $token->lastUsedAt ?? $token->createdAt,
            isCurrent: $token->id === $currentTokenId,
        ));

        return $webSessions->toBase()
            ->concat($tokens)
            ->sortBy([
                static fn (ActiveSession $a, ActiveSession $b): int => $b->isCurrent <=> $a->isCurrent,
                static fn (ActiveSession $a, ActiveSession $b): int => ($b->lastActiveAt?->getTimestamp() ?? 0) <=> ($a->lastActiveAt?->getTimestamp() ?? 0),
            ])
            ->values();
    }

    /**
     * Revoke one web session or access token of the user, by the id the list
     * gives it.
     *
     * @return bool whether the user had it
     *
     * @throws CurrentSessionException when it is the one the request comes from
     */
    public function revoke(Request $request, Authenticatable $user, string $id): bool
    {
        if (str_starts_with($id, self::TOKEN_PREFIX)) {
            return $this->revokeToken($user, Str::after($id, self::TOKEN_PREFIX));
        }

        if (str_starts_with($id, self::SESSION_PREFIX)) {
            return $this->revokeWebSession($request, $user, Str::after($id, self::SESSION_PREFIX));
        }

        return false;
    }

    /**
     * Revoke every web session and access token of the user, except the one
     * the request comes from.
     */
    public function revokeOthers(Request $request, Authenticatable $user): void
    {
        $currentSessionHash = self::currentSessionHash($request);
        $currentTokenId = $this->tokens->currentTokenId($user);

        $this->webSessions($user)
            ->map(static fn (stdClass $row): string => self::sessionIdOf($row))
            ->reject(static fn (string $sessionId): bool => self::hashSessionId($sessionId) === $currentSessionHash)
            ->each(fn (string $sessionId) => $this->deleteWebSession($user, $sessionId));

        $this->tokens->list($user)
            ->reject(static fn (AccessToken $token): bool => $token->id === $currentTokenId)
            ->each(fn (AccessToken $token): bool => $this->tokens->revoke($user, $token->id));

        $this->cycleRememberToken($user);
    }

    /**
     * @throws CurrentSessionException
     */
    private function revokeToken(Authenticatable $user, string $tokenId): bool
    {
        if ($tokenId === $this->tokens->currentTokenId($user)) {
            throw new CurrentSessionException;
        }

        return $this->tokens->revoke($user, $tokenId);
    }

    /**
     * @throws CurrentSessionException
     */
    private function revokeWebSession(Request $request, Authenticatable $user, string $hash): bool
    {
        if ($hash === self::currentSessionHash($request)) {
            throw new CurrentSessionException;
        }

        $sessionId = $this->webSessions($user)
            ->map(static fn (stdClass $row): string => self::sessionIdOf($row))
            ->first(static fn (string $sessionId): bool => hash_equals(self::hashSessionId($sessionId), $hash));

        if ($sessionId === null) {
            return false;
        }

        $this->deleteWebSession($user, $sessionId);
        $this->cycleRememberToken($user);

        return true;
    }

    private function deleteWebSession(Authenticatable $user, string $sessionId): void
    {
        $this->sessionTable()->where('id', $sessionId)->delete();

        $this->events->dispatch(new SessionRevoked($user, self::hashSessionId($sessionId)));
    }

    /**
     * Get the user's web sessions that have not expired. Only the "database"
     * session driver stores them where they can be listed.
     *
     * @return Collection<int, stdClass>
     */
    private function webSessions(Authenticatable $user): Collection
    {
        if ($this->config->get('session.driver') !== 'database') {
            return new Collection;
        }

        $lifetimeMinutes = $this->config->integer('session.lifetime', 120);

        return $this->sessionTable()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('last_activity', '>=', Date::now()->subMinutes($lifetimeMinutes)->getTimestamp())
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);
    }

    /**
     * Get the user's access tokens that can still authenticate: not expired,
     * and not idle for longer than "authentication.tokens.idle_expires_in_minutes".
     *
     * @return Collection<int, AccessToken>
     */
    private function activeTokens(Authenticatable $user): Collection
    {
        $idleCutoff = $this->config->get('authentication.tokens.idle_expires_in_minutes') === null
            ? null
            : Date::now()->subMinutes($this->config->integer('authentication.tokens.idle_expires_in_minutes'));

        return $this->tokens->list($user)->filter(static function (AccessToken $token) use ($idleCutoff): bool {
            $lastActivity = $token->lastUsedAt ?? $token->createdAt;

            return ($token->expiresAt === null || $token->expiresAt->isFuture())
                && ($idleCutoff === null || ($lastActivity !== null && $lastActivity->greaterThan($idleCutoff)));
        })->values();
    }

    /**
     * Give the user a new remember token, so no "remember me" cookie logs a
     * revoked browser back in. Users without one are left alone.
     */
    private function cycleRememberToken(Authenticatable $user): void
    {
        if (in_array($user->getRememberToken(), [null, ''], true)) {
            return;
        }

        $this->users->resolve()->updateRememberToken($user, Str::random(60));
    }

    private function sessionTable(): Builder
    {
        $connection = $this->config->get('session.connection');

        return $this->database->connection(is_string($connection) ? $connection : null)
            ->table($this->config->string('session.table', 'sessions'));
    }

    /**
     * Get the id of a row of the session table.
     */
    private static function sessionIdOf(stdClass $row): string
    {
        $sessionId = data_get($row, 'id');

        if (! is_string($sessionId)) {
            throw new LogicException('The session table must store the session ids as strings.');
        }

        return $sessionId;
    }
}
