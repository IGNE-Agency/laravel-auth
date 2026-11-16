<?php

declare(strict_types=1);

namespace Igne\Auth\Sessions;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Arr;

/**
 * Builds the session of a token-mode request. Its ID comes from a header
 * instead of a cookie:
 *
 * - after login, the bearer token (an HMAC of it, never the token itself);
 * - before login, the challenge header ("X-Auth-Challenge");
 * - otherwise, a fresh random ID.
 */
final class HeaderSessionFactory
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Repository $config,
    ) {}

    /**
     * Build the header session for the request. The session is not started.
     */
    public function forRequest(Request $request): Store
    {
        $bearerToken = $request->bearerToken();

        if (is_string($bearerToken) && $bearerToken !== '') {
            return $this->make($this->idForToken($bearerToken), $this->minutes('lifetime_minutes'));
        }

        $challengeId = $request->header($this->challengeHeader());

        return $this->make(
            is_string($challengeId) ? $challengeId : null,
            $this->minutes('challenge_lifetime_minutes'),
        );
    }

    /**
     * Get the session ID that belongs to a bearer token.
     */
    public function idForToken(string $bearerToken): string
    {
        return substr(hash_hmac('sha256', $bearerToken, (string) $this->config->get('app.key')), 0, 40);
    }

    /**
     * Get the name of the header that carries the ID of a challenge session.
     */
    public function challengeHeader(): string
    {
        return (string) $this->config->get('authentication.header_sessions.challenge_header', 'X-Auth-Challenge');
    }

    /**
     * Store the session after the request. A session without data is not
     * stored, and an ID the request moved away from is destroyed, so an
     * incoming challenge ID can't be used twice.
     */
    public function persist(Store $session, string $incomingId): void
    {
        if (Arr::except($session->all(), ['_token', '_flash', '_previous']) === []) {
            $session->getHandler()->destroy($session->getId());
        } else {
            $session->save();
        }

        if ($incomingId !== $session->getId()) {
            $session->getHandler()->destroy($incomingId);
        }
    }

    /**
     * Build a session with the given ID. An invalid or missing ID gets a fresh
     * random one.
     */
    private function make(?string $id, int $minutes): Store
    {
        $store = $this->config->get('authentication.header_sessions.cache_store');

        return new Store(
            'authentication_header_session',
            new HeaderSessionHandler($this->cache->store(is_string($store) ? $store : null), $minutes),
            $id,
        );
    }

    private function minutes(string $key): int
    {
        return (int) $this->config->get("authentication.header_sessions.{$key}");
    }
}
