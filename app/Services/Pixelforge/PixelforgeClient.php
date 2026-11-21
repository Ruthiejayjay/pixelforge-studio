<?php

namespace App\Services\Pixelforge;

use App\Exceptions\PixelforgeNonRetryableException;
use App\Exceptions\PixelforgeRetryableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    private ?int $lastRateLimitRemaining = null;

    private ?int $lastRateLimitLimit = null;

    public function getLastRateLimitRemaining(): ?int
    {
        return $this->lastRateLimitRemaining;
    }

    public function getLastRateLimitLimit(): ?int
    {
        return $this->lastRateLimitLimit;
    }

    /**
     * GET /v1/pricing (unauthenticated).
     *
     * @return array<string, mixed>
     */
    public function getPricing(): array
    {
        try {
            $response = $this->baseRequest()->get('/pricing');
        } catch (ConnectionException $e) {
            throw new PixelforgeRetryableException('connection_error', 503, 2, 'Unable to reach Pixelforge pricing endpoint: '.$e->getMessage());
        }

        return $this->handleResponse($response);
    }

    /**
     * POST /v1/generations with strict parameter allow-list and Idempotency-Key.
     *
     * @param  list<string>  $referenceImages
     * @param  array<string, string>  $metadata
     * @return array<string, mixed>
     */
    public function createGeneration(
        string $prompt,
        string $size,
        string $quality,
        array $referenceImages,
        array $metadata,
        string $idempotencyKey,
    ): array {
        $payload = [
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'metadata' => $metadata,
        ];

        if ($referenceImages !== []) {
            $payload['reference_images'] = array_values($referenceImages);
        }

        try {
            $response = $this->authenticatedRequest()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('/generations', $payload);
        } catch (ConnectionException $e) {
            throw new PixelforgeRetryableException('connection_error', 503, 2, 'Network error contacting Pixelforge: '.$e->getMessage());
        }

        return $this->handleResponse($response);
    }

    /**
     * GET /v1/generations/{id}
     *
     * @return array<string, mixed>
     */
    public function getGeneration(string $providerGenerationId): array
    {
        try {
            $response = $this->authenticatedRequest()->get("/generations/{$providerGenerationId}");
        } catch (ConnectionException $e) {
            throw new PixelforgeRetryableException('connection_error', 503, 2, 'Network error polling Pixelforge: '.$e->getMessage());
        }

        return $this->handleResponse($response);
    }

    /**
     * GET /v1/usage?start_date=...&end_date=...
     *
     * @return array<string, mixed>
     */
    public function getUsage(string $startDate, string $endDate): array
    {
        try {
            $response = $this->authenticatedRequest()->get('/usage', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
        } catch (ConnectionException $e) {
            throw new PixelforgeRetryableException('connection_error', 503, 2, 'Network error fetching Pixelforge usage: '.$e->getMessage());
        }

        return $this->handleResponse($response);
    }

    private function baseRequest(): PendingRequest
    {
        return Http::baseUrl((string) config('services.pixelforge.base_url'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function authenticatedRequest(): PendingRequest
    {
        $apiKey = (string) config('services.pixelforge.api_key');

        if ($apiKey === '') {
            throw new PixelforgeNonRetryableException(
                errorCode: 'missing_api_key',
                statusCode: 401,
                message: 'PIXELFORGE_API_KEY is not configured.',
                errorType: 'authentication_error',
            );
        }

        return $this->baseRequest()->withToken($apiKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResponse(Response $response): array
    {
        if ($response->hasHeader('X-RateLimit-Remaining')) {
            $this->lastRateLimitRemaining = (int) $response->header('X-RateLimit-Remaining');
        }

        if ($response->hasHeader('X-RateLimit-Limit')) {
            $this->lastRateLimitLimit = (int) $response->header('X-RateLimit-Limit');
        }

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $status = $response->status();
        $error = $response->json('error') ?? [];
        $code = (string) ($error['code'] ?? "http_{$status}");
        $type = (string) ($error['type'] ?? 'api_error');
        $message = (string) ($error['message'] ?? "Pixelforge request failed with HTTP {$status}.");
        $validationErrors = is_array($error['errors'] ?? null) ? $error['errors'] : [];
        $retryAfter = max(1, (int) ($response->header('Retry-After') ?: 2));

        $isRetryable = $status >= 500
            || $code === 'overloaded'
            || ($status === 429 && in_array($code, ['rate_limit_exceeded', 'concurrency_limit_exceeded'], true));

        if ($isRetryable) {
            throw new PixelforgeRetryableException(
                errorCode: $code,
                statusCode: $status,
                retryAfterSeconds: $retryAfter,
                message: $message,
                errorType: $type,
            );
        }

        throw new PixelforgeNonRetryableException(
            errorCode: $code,
            statusCode: $status,
            message: $message,
            errorType: $type,
            validationErrors: $validationErrors,
        );
    }
}