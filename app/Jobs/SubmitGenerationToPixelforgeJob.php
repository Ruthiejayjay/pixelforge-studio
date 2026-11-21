<?php

namespace App\Jobs;

use App\Exceptions\PixelforgeNonRetryableException;
use App\Exceptions\PixelforgeRetryableException;
use App\Models\Generation;
use App\Services\Generations\GenerationStateService;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubmitGenerationToPixelforgeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $generationId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        PixelforgeClient $client,
        GenerationStateService $stateService,
    ): void {
        $generation = Generation::query()->find($this->generationId);
        if (! $generation || $generation->status->isTerminal() || $generation->provider_generation_id !== null) {
            return;
        }

        try {
            $response = $client->createGeneration(
                prompt: $generation->prompt,
                size: $generation->size->value,
                quality: $generation->quality->value,
                referenceImages: $generation->reference_images ?? [],
                metadata: [
                    'local_generation_id' => (string) $generation->uuid,
                    'user_id' => (string) $generation->user_id,
                ],
                idempotencyKey: $generation->idempotency_key,
            );
            $stateService->applyProviderPayload($response, $generation);
        } catch (PixelforgeRetryableException $e) {
            if ($this->attempts() < $this->tries) {
                // Respect Retry-After while backing off further on repeated attempts
                $exponential = min(60, 2 ** $this->attempts());
                $delay = max($e->retryAfterSeconds, $exponential);
                Log::info('Retrying Pixelforge generation submission.', [
                    'generation_id' => $generation->id,
                    'attempt' => $this->attempts(),
                    'code' => $e->errorCode,
                    'delay_seconds' => $delay,
                ]);
                $this->release($delay);
                return;
            }
            $stateService->markLocalFailure(
                $generation,
                $e->errorCode,
                "Pixelforge remained unavailable after {$this->tries} attempts ({$e->getMessage()}). You were not charged.",
            );
        } catch (PixelforgeNonRetryableException $e) {
            $stateService->markLocalFailure(
                $generation,
                $e->errorCode,
                $e->getMessage(),
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        $generation = Generation::query()->find($this->generationId);
        if (! $generation || $generation->status->isTerminal()) {
            return;
        }
        app(GenerationStateService::class)->markLocalFailure(
            $generation,
            'submission_failed',
            'We could not submit this generation to Pixelforge. You were not charged.',
        );
    }
}
