<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Exceptions\PixelforgeNonRetryableException;
use App\Exceptions\PixelforgeRetryableException;
use App\Models\Generation;
use App\Services\Generations\GenerationStateService;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcilePendingGenerationsCommand extends Command
{
    protected $signature = 'pixelforge:reconcile-pending {--min-age=45 : Minimum seconds since last update before polling}';

    protected $description = 'Poll Pixelforge for non-terminal generations in case webhook deliveries were missed.';

    public function handle(
        PixelforgeClient $client,
        GenerationStateService $stateService,
    ): int {
        $minAge = max(0, (int) $this->option('min-age'));

        $pending = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_generation_id')
            ->where('updated_at', '<=', now()->subSeconds($minAge))
            ->orderBy('updated_at')
            ->limit(25)
            ->get();

        $updated = 0;

        foreach ($pending as $generation) {
            try {
                $payload = $client->getGeneration((string) $generation->provider_generation_id);
                $stateService->applyProviderPayload($payload, $generation);
                $updated++;
            } catch (PixelforgeRetryableException $e) {
                Log::warning('Pausing Pixelforge polling reconciliation due to retryable error.', [
                    'generation_id' => $generation->id,
                    'code' => $e->errorCode,
                    'retry_after' => $e->retryAfterSeconds,
                ]);
                break;
            } catch (PixelforgeNonRetryableException $e) {
                Log::warning('Non-retryable error polling Pixelforge generation.', [
                    'generation_id' => $generation->id,
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                ]);
            }

            // Leave headroom in the 60 req/min account rate limit for live submissions.
            $remaining = $client->getLastRateLimitRemaining();
            if ($remaining !== null && $remaining <= 5) {
                break;
            }
        }

        $this->info("Reconciled {$updated} pending generation(s).");

        return self::SUCCESS;
    }
}