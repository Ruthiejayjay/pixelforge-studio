<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_parses_and_formats_exact_nine_decimal_usd_strings(): void
    {
        $money = Money::fromDecimalString('0.046125000');
        $this->assertSame(46_125_000, $money->nanos);
        $this->assertSame('0.046125000', $money->toExactUsdString());
        $this->assertSame('$0.046125', $money->toDisplayUsd());
        $negative = Money::fromDecimalString('-1.25');
        $this->assertSame(-1_250_000_000, $negative->nanos);
        $this->assertSame('-1.250000000', $negative->toExactUsdString());
        $this->assertSame('-$1.25', $negative->toDisplayUsd());
    }

    public function test_applies_exact_25_percent_markup_without_float_drift(): void
    {
        // Exact Pixelforge GET /v1/usage example: $0.169080000 * 1.25 = $0.211350000
        $providerCost = Money::fromDecimalString('0.169080000');
        $userCost = $providerCost->withMarkupPercent(25);
        $this->assertSame(211_350_000, $userCost->nanos);
        $this->assertSame('0.211350000', $userCost->toExactUsdString());
    }
    public function test_rounds_up_sub_nanodollar_markup_fractions_for_odd_future_rates(): void
    {
        // Hypothetical 3,330 nanos * 1.25 = 4,162.5 nanos -> ceil to 4,163 nanos
        $odd = Money::fromNanodollars(3_330);
        $this->assertSame(4_163, $odd->withMarkupPercent(25)->nanos);
        $this->assertSame(0, Money::zero()->withMarkupPercent(25)->nanos);
    }
    public function test_rejects_malformed_decimal_strings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimalString('$12.50');
    }
}
