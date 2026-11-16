<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes\Generators;

/**
 * Generates the plain text of emailed codes. Swap it in
 * "authentication.login_codes.code_generator".
 */
interface LoginCodeGenerator
{
    /**
     * Generate a random code of the given length.
     */
    public function generate(int $length): string;
}
