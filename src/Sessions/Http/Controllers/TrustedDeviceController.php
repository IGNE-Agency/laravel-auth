<?php

declare(strict_types=1);

namespace Igne\Auth\Sessions\Http\Controllers;

use Igne\Auth\Sessions\Http\Resources\TrustedDeviceResource;
use Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable;
use Igne\Auth\TwoFactor\DeviceTrust;
use Igne\Auth\TwoFactor\Models\TrustedDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Lists and revokes the devices the authenticated user passed the two-factor
 * challenge on. A device of another user is not found.
 */
final class TrustedDeviceController
{
    public function index(Request $request, DeviceTrust $deviceTrust): AnonymousResourceCollection
    {
        $user = $request->user();

        if (! $user instanceof TwoFactorAuthenticatable) {
            return TrustedDeviceResource::collection([]);
        }

        $currentDeviceIds = $deviceTrust->currentDeviceIds($request, $user);

        $devices = $user->trustedDevices()
            ->latest('verified_at')
            ->get()
            ->filter(static fn (TrustedDevice $device): bool => $device->isActive())
            ->map(static fn (TrustedDevice $device): TrustedDeviceResource => new TrustedDeviceResource(
                $device,
                in_array($device->id, $currentDeviceIds, true),
            ))
            ->values();

        return TrustedDeviceResource::collection($devices);
    }

    /**
     * Revoke the device. Its next login asks for the two-factor challenge
     * again; when it is the device the request comes from, its next request
     * does.
     */
    public function destroy(Request $request, DeviceTrust $deviceTrust, string $trustedDevice): Response
    {
        $user = $request->user();

        abort_unless($user instanceof TwoFactorAuthenticatable, Response::HTTP_NOT_FOUND, Response::$statusTexts[Response::HTTP_NOT_FOUND]);

        $device = $user->trustedDevices()->whereKey($trustedDevice)->first();

        abort_unless($device instanceof TrustedDevice, Response::HTTP_NOT_FOUND, Response::$statusTexts[Response::HTTP_NOT_FOUND]);

        $deviceTrust->revoke($user, $device, 'revoked');

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
