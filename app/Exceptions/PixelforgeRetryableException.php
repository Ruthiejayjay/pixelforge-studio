<?php

namespace App\Exceptions;

use RuntimeException;

class PixelforgeRetryableException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $statusCode,
        public readonly int $retryAfterSeconds,
        string $message,
        public readonly string $errorType = 'api_error',
    ) {
        parent::__construct($message, $statusCode);
    }
}
