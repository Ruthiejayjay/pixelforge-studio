<?php

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Billing\CreditLedgerService;
use App\Services\Pixelforge\PricingCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GenerationStateService
{
    public function __construct(
        private readonly PricingCalculator $pricingCalculator,
        private readonly CreditLedgerService $ledger,
    ) {}

    /**
     * Apply a generation object returned by Pixelforge (from POST 202, webhook, or GET poll).
     *
     * @param  array<string, mixed>  $providerData
     */
    public function applyProviderPayload(array $providerData, ?Generation $knownGeneration = null): ?Generation
    {
        return DB::transaction(function () use ($providerData, $knownGeneration): ?Generation {
            $generation = $this->findAndLockGeneration($providerData, $knownGeneration);

            if (! $generation) {
                return null;
            }

            $providerId = isset($providerData['id']) ? (string) $providerData['id'] : null;

            if ($providerId && $generation->provider_generation_id === null) {
                $generation->provider_generation_id = $providerId;
            }

            if (is_array($providerData['rates'] ?? null) && $generation->provider_rates === null) {
                $generation->provider_rates = $providerData['rates'];
            }

            if (! empty($providerData['created_at']) && $generation->provider_created_at === null) {
                $generation->provider_created_at = Carbon::parse($providerData['created_at'])->utc();
            }

            if (! empty($providerData['started_at']) && $generation->provider_started_at === null) {
                $generation->provider_started_at = Carbon::parse($providerData['started_at'])->utc();
            }

            $incomingStatus = GenerationStatus::fromProviderStatus((string) ($providerData['status'] ?? 'queued'));

            // Terminal states are immutable: save any backfilled provider metadata (e.g. from late 202) and exit.
            if ($generation->status->isTerminal()) {
                if ($generation->isDirty()) {
                    $generation->save();
                }

                return $generation;
            }

            if (! $generation->status->canTransitionTo($incomingStatus)) {
                if ($generation->isDirty()) {
                    $generation->save();
                }

                return $generation;
            }

            $generation->status = $incomingStatus;

            if ($incomingStatus === GenerationStatus::Succeeded) {
                $usage = is_array($providerData['usage'] ?? null) ? $providerData['usage'] : [];
                $rates = is_array($providerData['rates'] ?? null)
                    ? $providerData['rates']
                    : ($generation->provider_rates ?? []);

                $providerCostNanos = $this->pricingCalculator->calculateProviderCostNanos($usage, $rates);
                $userCostNanos = $this->pricingCalculator->calculateUserCostNanos($providerCostNanos);

                $completedAt = ! empty($providerData['completed_at'])
                    ? Carbon::parse($providerData['completed_at'])->utc()
                    : now('UTC');

                $generation->provider_usage = $usage;
                $generation->provider_rates = $rates;
                $generation->actual_provider_cost_nanos = $providerCostNanos;
                $generation->actual_user_cost_nanos = $userCostNanos;
                $generation->image_url = $providerData['image_url'] ?? null;
                $generation->image_url_expires_at = ! empty($providerData['image_url_expires_at'])
                    ? Carbon::parse($providerData['image_url_expires_at'])->utc()
                    : null;
                $generation->provider_completed_at = $completedAt;
                $generation->completed_utc_date = $completedAt->toDateString();
                $generation->error_code = null;
                $generation->error_message = null;
                $generation->save();

                $this->ledger->settleGenerationSuccess($generation, $userCostNanos);

                return $generation;
            }

            if ($incomingStatus === GenerationStatus::Failed) {
                $error = is_array($providerData['error'] ?? null) ? $providerData['error'] : [];
                $completedAt = ! empty($providerData['completed_at'])
                    ? Carbon::parse($providerData['completed_at'])->utc()
                    : now('UTC');

                $generation->actual_provider_cost_nanos = 0;
                $generation->actual_user_cost_nanos = 0;
                $generation->error_code = (string) ($error['code'] ?? 'generation_failed');
                $generation->error_message = (string) ($error['message'] ?? 'Generation failed. You were not charged.');
                $generation->provider_completed_at = $completedAt;
                $generation->completed_utc_date = $completedAt->toDateString();
                $generation->save();

                $this->ledger->refundGenerationFailure($generation);

                return $generation;
            }

            $generation->save();

            return $generation;
        });
    }

    /**
     * Mark a generation as failed locally (e.g. 400 content_policy_violation or exhausted retries)
     * and refund 100% of its reserved balance.
     */
    public function markLocalFailure(Generation $generation, string $errorCode, string $errorMessage): Generation
    {
        return DB::transaction(function () use ($generation, $errorCode, $errorMessage): Generation {
            /** @var Generation $locked */
            $locked = Generation::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                return $locked;
            }

            $locked->status = GenerationStatus::Failed;
            $locked->actual_provider_cost_nanos = 0;
            $locked->actual_user_cost_nanos = 0;
            $locked->error_code = $errorCode;
            $locked->error_message = $errorMessage;
            $locked->save();

            $this->ledger->refundGenerationFailure($locked);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $providerData
     */
    private function findAndLockGeneration(array $providerData, ?Generation $knownGeneration): ?Generation
    {
        if ($knownGeneration !== null) {
            return Generation::query()->whereKey($knownGeneration->id)->lockForUpdate()->first();
        }

        $providerId = isset($providerData['id']) ? (string) $providerData['id'] : null;

        if ($providerId) {
            $byProvider = Generation::query()
                ->where('provider_generation_id', $providerId)
                ->lockForUpdate()
                ->first();

            if ($byProvider) {
                return $byProvider;
            }
        }

        $localUuid = is_array($providerData['metadata'] ?? null)
            ? ($providerData['metadata']['local_generation_id'] ?? null)
            : null;

        if (is_string($localUuid) && $localUuid !== '') {
            return Generation::query()
                ->where('uuid', $localUuid)
                ->lockForUpdate()
                ->first();
        }

        return null;
    }
}