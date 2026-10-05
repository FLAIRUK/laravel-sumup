<?php

namespace FLAIRUK\SumUp\Exceptions;

use SumUp\Exception\ApiException as SdkApiException;
use Throwable;

/**
 * SumUp answered with an error status (4xx or 5xx).
 */
class ApiException extends SumUpException
{
    /**
     * @param  mixed  $body  The decoded error body: an SDK error type (e.g. \SumUp\Types\Problem), an array or a string.
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly mixed $body = null,
        public readonly ?string $method = null,
        public readonly ?string $path = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function fromSdk(SdkApiException $e): self
    {
        return new self(
            "SumUp API request failed ({$e->getStatusCode()}): {$e->getMessage()}",
            $e->getStatusCode(),
            $e->getResponseBody(),
            $e->getHttpMethod(),
            $e->getPath(),
            $e,
        );
    }

    public function notFound(): bool
    {
        return $this->status === 404;
    }
}
