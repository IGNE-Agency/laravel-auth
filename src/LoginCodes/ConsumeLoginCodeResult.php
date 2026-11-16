<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes;

/**
 * The outcome of consuming an emailed code.
 */
enum ConsumeLoginCodeResult
{
    /**
     * The code was correct and is now used up.
     */
    case Ok;

    /**
     * The user has no unused code for the purpose, or another request used it
     * first.
     */
    case NotFound;

    /**
     * The code did not match. It counts as a failed attempt.
     */
    case Incorrect;

    /**
     * The code expired.
     */
    case Expired;

    /**
     * The code had too many failed attempts and no longer works.
     */
    case TooManyAttempts;

    /**
     * The user had too many failed attempts across codes, so no code is
     * checked until the limit's time window passes.
     */
    case RateLimited;
}
