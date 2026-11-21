<?php

namespace App\Exceptions;

use RuntimeException;

class PixelforgeNonRetryableException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $statusCode,
        string $message,
        public readonly string $errorType = 'invalid_request_error',
        public readonly array $validationErrors = [],
    ) {
        parent::__construct($message, $statusCode);
    }
}
