<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Exceptions;

use InvalidArgumentException;

/**
 * A two-factor setting in "config/authentication.php" has a value the package
 * can't use.
 */
final class InvalidConfigurationException extends InvalidArgumentException
{
    /**
     * A device trust lifetime is not a number of days from 1 to the maximum.
     */
    public static function invalidDays(string $key, mixed $value, int $maxDays, bool $allowsSession): self
    {
        return new self(sprintf(
            'The [authentication.two_factor.device_trust.%s] setting must be %sa number of days from 1 to %d, %s given.',
            $key,
            $allowsSession ? '"session" or ' : '',
            $maxDays,
            (string) json_encode($value),
        ));
    }
}
