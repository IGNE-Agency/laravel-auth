<?php

declare(strict_types=1);

namespace Igne\Auth\Sessions;

use Illuminate\Session\CacheBasedSessionHandler;

/**
 * Stores header sessions in the cache under their own key prefix, so a header
 * session ID can never load a cookie session from the same cache store.
 */
final class HeaderSessionHandler extends CacheBasedSessionHandler
{
    private const string KEY_PREFIX = 'authentication:header-session:';

    public function read($sessionId): string
    {
        return parent::read(self::KEY_PREFIX.$sessionId);
    }

    public function write($sessionId, $data): bool
    {
        return parent::write(self::KEY_PREFIX.$sessionId, $data);
    }

    public function destroy($sessionId): bool
    {
        return parent::destroy(self::KEY_PREFIX.$sessionId);
    }
}
