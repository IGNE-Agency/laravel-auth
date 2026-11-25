<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Controllers;

use Igne\Auth\Http\Requests\ConfirmPasswordRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\ConfirmedPasswordStatusController;

/**
 * Confirming the identity of the logged-in user before a sensitive action.
 */
final class PasswordConfirmationController
{
    /**
     * Confirm the identity of the user.
     *
     * A route that needs a recent confirmation answers 423 until this passes.
     *
     * @response \Illuminate\Http\JsonResponse<'', 201>
     *
     * @throws ValidationException
     */
    public function store(ConfirmPasswordRequest $request, ConfirmablePasswordController $fortify): mixed
    {
        return $fortify->store($request);
    }

    /**
     * Get the confirmation status.
     *
     * @response array{confirmed: bool}
     */
    public function show(Request $request, ConfirmedPasswordStatusController $fortify): mixed
    {
        return $fortify->show($request);
    }
}
