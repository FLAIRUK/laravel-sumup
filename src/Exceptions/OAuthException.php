<?php

namespace FLAIRUK\SumUp\Exceptions;

use Illuminate\Http\Client\Response;

/**
 * The OAuth authorisation was denied, or the token endpoint rejected a request.
 */
class OAuthException extends SumUpException
{
    public function __construct(
        string $message,
        public readonly ?string $error = null,
        public readonly ?Response $response = null,
    ) {
        parent::__construct($message, $response?->status() ?? 0);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error');
        $description = $response->json('error_description') ?: $response->json('message') ?: $error ?: $response->reason();

        return new self("SumUp OAuth request failed ({$response->status()}): {$description}", is_string($error) ? $error : null, $response);
    }
}
