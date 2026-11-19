<?php

declare(strict_types=1);

namespace Igne\Auth\OpenApi\Schemas;

use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Igne\Auth\Http\Channel;

/**
 * Marks a part of the document that only applies in one authentication mode
 * with the "x-auth-mode" extension ("session" or "token"), so a document for
 * one mode can leave out the parts of the other.
 */
final class AuthenticationMode
{
    /**
     * The extension name, without Scramble's "x-" prefix.
     */
    public const string EXTENSION = 'auth-mode';

    /**
     * @template TPart of Type|Parameter|Response|SecurityScheme
     *
     * @param  TPart  $part
     * @return TPart
     */
    public static function mark(Type|Parameter|Response|SecurityScheme $part, Channel $mode): Type|Parameter|Response|SecurityScheme
    {
        $part->setExtensionProperty(self::EXTENSION, $mode->value);

        return $part;
    }

    /**
     * Get the mode the part is marked with; null when it applies in both.
     */
    public static function of(Type|Parameter|Response|SecurityScheme $part): ?Channel
    {
        $mode = $part->getExtensionProperty(self::EXTENSION);

        return is_string($mode) ? Channel::tryFrom($mode) : null;
    }
}
