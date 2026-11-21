<?php

namespace App\Enums;

use InvalidArgumentException;

enum GenerationStatus: string
{
    case Submitting = 'submitting';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function rank(): int
    {
        return match ($this) {
            self::Submitting => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this->isTerminal()) {
            return false;
        }
        return $next->rank() > $this->rank();
    }

    public static function fromProviderStatus(string $status): self
    {
        return match ($status) {
            'queued' => self::Queued,
            'processing' => self::Processing,
            'succeeded' => self::Succeeded,
            'failed' => self::Failed,
            default => throw new InvalidArgumentException("Unknown Pixelforge status: {$status}"),
        };
    }
}
