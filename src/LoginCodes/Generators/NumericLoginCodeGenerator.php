<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes\Generators;

/**
 * Generates codes of random digits, which are easy to type on any keyboard.
 */
final class NumericLoginCodeGenerator implements LoginCodeGenerator
{
    public function generate(int $length): string
    {
        $code = '';

        for ($position = 0; $position < $length; $position++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }
}
