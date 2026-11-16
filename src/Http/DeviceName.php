<?php

declare(strict_types=1);

namespace Igne\Auth\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Names the client device of a request: the "device_name" field, or else the
 * user agent. Access tokens and trusted devices carry this name.
 */
final class DeviceName
{
    public static function of(Request $request): string
    {
        $deviceName = $request->input('device_name');

        return is_string($deviceName) && trim($deviceName) !== ''
            ? Str::limit(trim($deviceName), 255, '')
            : self::fromUserAgent($request->userAgent());
    }

    /**
     * Name a device after its user agent.
     */
    public static function fromUserAgent(?string $userAgent): string
    {
        $name = trim((string) $userAgent);

        return Str::limit($name !== '' ? $name : 'Unknown device', 255, '');
    }
}
