<?php

namespace App\Enums;

enum ImageSize: string
{
    case Square1024 = '1024x1024';
    case Landscape1536 = '1536x1024';
    case Portrait1536 = '1024x1536';

    public function label(): string
    {
        return match ($this) {
            self::Square1024 => '1024 × 1024 (Square)',
            self::Landscape1536 => '1536 × 1024 (Landscape)',
            self::Portrait1536 => '1024 × 1536 (Portrait)',
        };
    }
}