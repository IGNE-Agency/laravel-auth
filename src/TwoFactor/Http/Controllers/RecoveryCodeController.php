<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController as FortifyRecoveryCodeController;

final class RecoveryCodeController
{
    public function __construct(private readonly FortifyRecoveryCodeController $fortify) {}

    /**
     * Get the recovery codes.
     *
     * The codes that were not used yet.
     *
     * @response list<string>
     */
    public function index(Request $request): mixed
    {
        return $this->fortify->index($request);
    }

    /**
     * Replace the recovery codes.
     *
     * @response \Illuminate\Http\JsonResponse<'', 200>
     */
    public function store(Request $request, GenerateNewRecoveryCodes $generate): mixed
    {
        return $this->fortify->store($request, $generate);
    }
}
