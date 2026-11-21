<?php

namespace App\Support;

use InvalidArgumentException;

final readonly class Money
{
    public const int NANOS_PER_USD = 1_000_000_000;

    public function __construct(
        public int $nanos,
    ) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromNanodollars(int $nanos): self
    {
        return new self($nanos);
    }

    public static function fromDecimalString(string $usd): self
    {
        $normalized = trim($usd);

        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $normalized)) {
            throw new InvalidArgumentException("Invalid USD decimal string: {$usd}");
        }

        return new self((int) bcmul($normalized, (string) self::NANOS_PER_USD, 0));
    }

    public function add(self $other): self
    {
        return new self($this->nanos + $other->nanos);
    }

    public function subtract(self $other): self
    {
        return new self($this->nanos - $other->nanos);
    }

    /**
     * Apply percentage markup using exact bcmath integer ceiling so sub-nanodollar
     * fractions (if a future rate produces one) never cause silent revenue loss.
     */
    public function withMarkupPercent(int $percent = 25): self
    {
        if ($percent < 0) {
            throw new InvalidArgumentException('Markup percent cannot be negative.');
        }

        $numerator = bcmul((string) $this->nanos, (string) (100 + $percent), 0);
        $quotient = bcdiv($numerator, '100', 0);
        $remainder = bcmod($numerator, '100');

        if ($this->nanos > 0 && $remainder !== '0') {
            $quotient = bcadd($quotient, '1', 0);
        }

        return new self((int) $quotient);
    }

    /**
     * Exact 9-decimal USD string matching Pixelforge's GET /v1/usage cost_usd format.
     */
    public function toExactUsdString(): string
    {
        $sign = $this->nanos < 0 ? '-' : '';
        $abs = abs($this->nanos);
        $dollars = intdiv($abs, self::NANOS_PER_USD);
        $fraction = str_pad((string) ($abs % self::NANOS_PER_USD), 9, '0', STR_PAD_LEFT);

        return "{$sign}{$dollars}.{$fraction}";
    }

    /**
     * Human-friendly display string preserving precision up to 6 decimals (or 9 if needed).
     */
    public function toDisplayUsd(int $minDecimals = 2, int $maxDecimals = 6): string
    {
        $sign = $this->nanos < 0 ? '-' : '';
        $abs = abs($this->nanos);
        $dollars = intdiv($abs, self::NANOS_PER_USD);
        $fractionFull = str_pad((string) ($abs % self::NANOS_PER_USD), 9, '0', STR_PAD_LEFT);

        $trimmed = rtrim($fractionFull, '0');

        if (strlen($trimmed) <= $minDecimals) {
            $fraction = str_pad($trimmed, $minDecimals, '0');
        } elseif (strlen($trimmed) <= $maxDecimals) {
            $fraction = $trimmed;
        } else {
            // Preserve exactness when non-zero digits exist past maxDecimals
            $fraction = $trimmed;
        }

        return "{$sign}\${$dollars}.{$fraction}";
    }
}