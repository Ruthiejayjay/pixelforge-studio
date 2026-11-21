<?php

namespace Tests\Unit;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Pixelforge\PixelforgePricingService;
use App\Services\Pixelforge\PricingCalculator;
use App\Support\Money;
use Tests\TestCase;

class PricingCalculatorTest extends TestCase
{
    public function test_matches_pixelforge_docs_worked_maximum_example_exactly(): void
    {
        $calculator = new PricingCalculator;
        $pricing = PixelforgePricingService::defaultPricing();

        // Worked example from Pixelforge docs:
        // 400-char prompt, 2 reference images, high quality, 1536x1024 -> max $0.373875000
        $prompt = str_repeat('a', 400);
        $estimate = $calculator->estimate(
            prompt: $prompt,
            size: ImageSize::Landscape1536,
            quality: ImageQuality::High,
            referenceImageCount: 2,
            pricing: $pricing,
            markupPercent: 25,
        );

        $this->assertSame(100, $estimate['tokens']['input_text_expected']);
        $this->assertSame(115, $estimate['tokens']['input_text_max']);
        $this->assertSame(1530, $estimate['tokens']['input_image']);
        $this->assertSame(6450, $estimate['tokens']['output']);
        $this->assertSame(8000, $estimate['tokens']['reasoning_max']);

        $this->assertSame(373_875_000, $estimate['provider_cost_nanos']['max']);
        $this->assertSame('0.373875000', Money::fromNanodollars($estimate['provider_cost_nanos']['max'])->toExactUsdString());

        // +25% markup: 373,875,000 * 1.25 = 467,343,750 ($0.467343750)
        $this->assertSame(467_343_750, $estimate['user_cost_nanos']['max']);
        $this->assertSame('0.467343750', Money::fromNanodollars($estimate['user_cost_nanos']['max'])->toExactUsdString());
    }

    public function test_matches_pixelforge_usage_api_example_exactly(): void
    {
        $calculator = new PricingCalculator;
        $rates = PixelforgePricingService::defaultPricing()['rates'];

        // Exact example from GET /v1/usage docs:
        // 41 input text, 0 input image, 3300 output, 2950 reasoning -> $0.169080000
        $costNanos = $calculator->calculateProviderCostNanos([
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 3300,
            'reasoning_tokens' => 2950,
        ], $rates);

        $this->assertSame(169_080_000, $costNanos);
        $this->assertSame('0.169080000', Money::fromNanodollars($costNanos)->toExactUsdString());
    }

    public function test_counts_unicode_characters_rather_than_utf8_bytes(): void
    {
        $calculator = new PricingCalculator;
        $pricing = PixelforgePricingService::defaultPricing();

        // 400 emoji = 1,600 UTF-8 bytes, but 400 Unicode characters -> 100 base text tokens (115 max)
        $emojiPrompt = str_repeat('🎨', 400);
        $estimate = $calculator->estimate(
            prompt: $emojiPrompt,
            size: ImageSize::Square1024,
            quality: ImageQuality::Low,
            referenceImageCount: 0,
            pricing: $pricing,
        );

        $this->assertSame(400, $estimate['tokens']['prompt_characters']);
        $this->assertSame(100, $estimate['tokens']['input_text_expected']);
        $this->assertSame(115, $estimate['tokens']['input_text_max']);
    }

    public function test_calculates_provider_cost_using_generation_rate_snapshot(): void
    {
        $calculator = new PricingCalculator;

        $customRates = [
            'input_text_tokens' => '8.00',
            'input_image_tokens' => '20.00',
            'output_tokens' => '60.00',
            'reasoning_tokens' => '15.00',
        ];

        $costNanos = $calculator->calculateProviderCostNanos([
            'input_text_tokens' => 10,
            'input_image_tokens' => 765,
            'output_tokens' => 1000,
            'reasoning_tokens' => 200,
        ], $customRates);

        // 10*8000 + 765*20000 + 1000*60000 + 200*15000 = 80,000 + 15,300,000 + 60,000,000 + 3,000,000
        $this->assertSame(78_380_000, $costNanos);
    }
}