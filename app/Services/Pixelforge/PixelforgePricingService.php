<?php

namespace App\Services\Pixelforge;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class PixelforgePricingService
{
    private const string CACHE_KEY = 'pixelforge.pricing.current';

    private const string FALLBACK_CACHE_KEY = 'pixelforge.pricing.last_known';

    public function __construct(
        private readonly PixelforgeClient $client,
    ) {}

    /**
     * Fetch and cache live pricing from GET /v1/pricing, with graceful fallback.
     *
     * @return array<string, mixed>
     */
    public function getPricing(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['rates'], $cached['tokens'])) {
            return $cached;
        }

        try {
            $pricing = $this->client->getPricing();

            if (isset($pricing['rates'], $pricing['tokens'])) {
                $ttl = max(30, (int) config('services.pixelforge.pricing_cache_ttl', 300));
                Cache::put(self::CACHE_KEY, $pricing, now()->addSeconds($ttl));
                Cache::put(self::FALLBACK_CACHE_KEY, $pricing, now()->addDay());

                return $pricing;
            }
        } catch (Throwable $e) {
            Log::warning('Failed to fetch live Pixelforge pricing; using fallback.', [
                'error' => $e->getMessage(),
            ]);
        }

        $lastKnown = Cache::get(self::FALLBACK_CACHE_KEY);

        if (is_array($lastKnown) && isset($lastKnown['rates'], $lastKnown['tokens'])) {
            return $lastKnown;
        }

        return self::defaultPricing();
    }

    /**
     * Documented Pixelforge baseline rates used only if upstream is unreachable on cold boot.
     *
     * @return array<string, mixed>
     */
    public static function defaultPricing(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }
}