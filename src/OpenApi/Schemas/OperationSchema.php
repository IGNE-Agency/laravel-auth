<?php

declare(strict_types=1);

namespace Igne\Auth\OpenApi\Schemas;

use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Igne\Auth\Http\ErrorCode;

/**
 * The explicit documentation of one package route.
 */
final readonly class OperationSchema
{
    /**
     * @param  list<Response|Reference>  $responses  the route's own responses; the transformer adds the 401, 403, 422, 423 and 429 that follow from its middleware and request body
     * @param  list<Parameter>  $parameters
     * @param  bool|null  $throttled  whether the route can answer 429; null decides by its "throttle" middleware
     * @param  list<ErrorCode>  $errorCodes  the codes of the route's own 403 answers; the transformer adds the two-factor codes
     */
    public function __construct(
        public string $summary,
        public string $description = '',
        public ?ObjectType $requestBody = null,
        public array $responses = [],
        public array $parameters = [],
        public bool $validatesInput = false,
        public ?bool $throttled = null,
        public array $errorCodes = [],
    ) {}
}
