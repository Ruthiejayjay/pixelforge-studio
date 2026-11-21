<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $requiredNanos,
        public readonly int $availableNanos,
        string $message = 'Insufficient credit balance for this generation.',
    ) {
        parent::__construct($message);
    }
}
