<?php

namespace App\Services\Pixelforge;

class WebhookSignatureVerifier
{
    public function verify(
        string $rawPayload,
        ?string $signatureHeader,
        ?string $secret = null,
        ?int $toleranceSeconds = null,
        ?int $now = null,
    ): bool {
        $signingSecret = $secret ?? (string) config('services.pixelforge.webhook_secret');
        $tolerance = $toleranceSeconds ?? (int) config('services.pixelforge.webhook_tolerance_seconds', 300);
        $currentTime = $now ?? time();

        if ($signingSecret === '' || $signatureHeader === null || trim($signatureHeader) === '') {
            return false;
        }

        $parts = [];

        foreach (explode(',', $signatureHeader) as $segment) {
            [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, '');
            if ($key !== '') {
                $parts[$key] = $value;
            }
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t']) || $parts['v1'] === '') {
            return false;
        }

        $timestamp = (int) $parts['t'];

        if (abs($currentTime - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawPayload}", $signingSecret);

        return hash_equals($expected, $parts['v1']);
    }
}