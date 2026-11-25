<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Fortify\Http\Controllers\TwoFactorQrCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorSecretKeyController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The authenticator secret of the user, to add the account to an authenticator app.
 */
final class TwoFactorSecretController
{
    /**
     * Get the QR code of the authenticator secret.
     *
     * The QR code as SVG and its otpauth URL; empty before the setup started.
     *
     * @response array{svg?: string, url?: string}
     */
    public function qrCode(Request $request, TwoFactorQrCodeController $fortify): mixed
    {
        return $fortify->show($request);
    }

    /**
     * Get the authenticator secret.
     *
     * For entering the secret in an authenticator app by hand.
     *
     * @response array{secretKey: string}
     *
     * @throws NotFoundHttpException
     */
    public function secretKey(Request $request, TwoFactorSecretKeyController $fortify): mixed
    {
        return $fortify->show($request);
    }
}
