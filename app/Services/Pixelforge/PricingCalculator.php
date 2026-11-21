<?php

namespace App\Services\Pixelforge;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Support\Money;

class PricingCalculator
{
    public function calculateProviderCostNanos(array $usage, array $rates): int
    {
        $totalNanos = '0';

        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
            $tokens = (string) max(0, (int) ($usage[$key] ?? 0));
            $ratePerMillion = (string) ($rates[$key] ?? '0');

            $nanosPerToken = bcmul($ratePerMillion, '1000', 6);
            $lineNanos = bcmul($tokens, $nanosPerToken, 6);
            $totalNanos = bcadd($totalNanos, $lineNanos, 6);
        }
        return (int) bcadd($totalNanos, '0', 0);
    }

    public function calculateUserCostNanos(int $providerCostNanos, ?int $markupPercent = null): int
    {
        $markup = $markupPercent ?? (int) config('services.pixelforge.markup_percent', 25);
        return Money::fromNanodollars($providerCostNanos)
            ->withMarkupPercent($markup)
            ->nanos;
    }

    public function estimate(
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        int $referenceImageCount,
        array $pricing,
        ?int $markupPercent = null,
    ): array {
        $rates = $pricing['rates'];
        $tokensConfig = $pricing['tokens'];
        // Pixelforge counts Unicode characters, not bytes
        $promptChars = mb_strlen($prompt, 'UTF-8');
        $charsPerToken = max(1, (int) ($tokensConfig['characters_per_text_token'] ?? 4));
        $baseTextTokens = $promptChars > 0 ? (int) ceil($promptChars / $charsPerToken) : 0;

        $varianceStr = number_format((float) ($tokensConfig['text_token_variance'] ?? 0.15), 4, '.', '');
        $minTextTokens = $this->floorDecimal(bcmul((string) $baseTextTokens, bcsub('1', $varianceStr, 4), 4));
        $maxTextTokens = $this->ceilDecimal(bcmul((string) $baseTextTokens, bcadd('1', $varianceStr, 4), 4));

        $perRefImage = (int) ($tokensConfig['per_reference_image'] ?? 765);
        $imageTokens = max(0, $referenceImageCount) * $perRefImage;
        $outputTokens = (int) ($tokensConfig['output'][$quality->value][$size->value] ?? 0);
        $minReasoningTokens = (int) ($tokensConfig['reasoning'][$quality->value]['min'] ?? 0);
        $maxReasoningTokens = (int) ($tokensConfig['reasoning'][$quality->value]['max'] ?? 0);
        $expectedReasoningTokens = intdiv($minReasoningTokens + $maxReasoningTokens, 2);

        $minProviderNanos = $this->calculateProviderCostNanos([
            'input_text_tokens' => $minTextTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $minReasoningTokens,
        ], $rates);
        $expectedProviderNanos = $this->calculateProviderCostNanos([
            'input_text_tokens' => $baseTextTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $expectedReasoningTokens,
        ], $rates);
        $maxProviderNanos = $this->calculateProviderCostNanos([
            'input_text_tokens' => $maxTextTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $maxReasoningTokens,
        ], $rates);

        return [
            'tokens' => [
                'prompt_characters' => $promptChars,
                'input_text_min' => $minTextTokens,
                'input_text_expected' => $baseTextTokens,
                'input_text_max' => $maxTextTokens,
                'input_image' => $imageTokens,
                'output' => $outputTokens,
                'reasoning_min' => $minReasoningTokens,
                'reasoning_expected' => $expectedReasoningTokens,
                'reasoning_max' => $maxReasoningTokens,
            ],
            'provider_cost_nanos' => [
                'min' => $minProviderNanos,
                'expected' => $expectedProviderNanos,
                'max' => $maxProviderNanos,
            ],
            'user_cost_nanos' => [
                'min' => $this->calculateUserCostNanos($minProviderNanos, $markupPercent),
                'expected' => $this->calculateUserCostNanos($expectedProviderNanos, $markupPercent),
                'max' => $this->calculateUserCostNanos($maxProviderNanos, $markupPercent),
            ],
        ];
    }

    private function floorDecimal(string $decimal): int
    {
        return max(0, (int) bcadd($decimal, '0', 0));
    }
    private function ceilDecimal(string $decimal): int
    {
        $truncated = bcadd($decimal, '0', 0);
        if (bccomp($decimal, $truncated, 4) > 0) {
            return (int) bcadd($truncated, '1', 0);
        }
        return (int) $truncated;
    }
}
