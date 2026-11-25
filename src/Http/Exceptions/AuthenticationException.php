<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The base of the package's errors: a 403 the user can act on.
 *
 * The package only throws. It never renders an error: the exception handler
 * of the app decides what the answer looks like, as for any other HTTP
 * exception. Laravel's own handler answers {"message": ...} with the status;
 * a handler that gives every error of the API one shape names the error after
 * its class (TwoFactorRequiredException is "two_factor_required").
 *
 * So each error has a class of its own, built without arguments, with a
 * message that is safe to show. The class name is public API.
 */
abstract class AuthenticationException extends AccessDeniedHttpException {}
