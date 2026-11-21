<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\PixelforgeNonRetryableException;
use App\Exceptions\PixelforgeRetryableException;
use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\SubmitGenerationToPixelforgeJob;
use App\Models\Generation;
use App\Services\Billing\CreditLedgerService;
use App\Services\Generations\GenerationStateService;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgePricingService;
use App\Services\Pixelforge\PricingCalculator;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerationController extends Controller
{
    public function store(
        StoreGenerationRequest $request,
        PixelforgePricingService $pricingService,
        PricingCalculator $calculator,
        CreditLedgerService $ledger,
    ): RedirectResponse {
        $validated = $request->validated();
        $user = $request->user();
        $size = ImageSize::from($validated['size']);
        $quality = ImageQuality::from($validated['quality']);
        $referenceImages = $validated['reference_images'] ?? [];

        $pricing = $pricingService->getPricing();
        $estimate = $calculator->estimate(
            prompt: $validated['prompt'],
            size: $size,
            quality: $quality,
            referenceImageCount: count($referenceImages),
            pricing: $pricing,
        );

        $maxUserCostNanos = $estimate['user_cost_nanos']['max'];

        try {
            $generation = DB::transaction(function () use (
                $user,
                $validated,
                $size,
                $quality,
                $referenceImages,
                $estimate,
                $maxUserCostNanos,
                $ledger,
            ): Generation {
                $uuid = (string) Str::uuid();
                $generation = Generation::create([
                    'uuid' => $uuid,
                    'user_id' => $user->id,
                    'idempotency_key' => "genreq_{$uuid}",
                    'status' => GenerationStatus::Submitting,
                    'prompt' => $validated['prompt'],
                    'size' => $size,
                    'quality' => $quality,
                    'reference_images' => $referenceImages,
                    'estimated_min_user_cost_nanos' => $estimate['user_cost_nanos']['min'],
                    'estimated_expected_user_cost_nanos' => $estimate['user_cost_nanos']['expected'],
                    'estimated_max_user_cost_nanos' => $maxUserCostNanos,
                    'reserved_user_cost_nanos' => $maxUserCostNanos,
                ]);
                $ledger->placeHold($user, $generation, $maxUserCostNanos);
                return $generation;
            });
        } catch (InsufficientCreditsException $e) {
            $required = Money::fromNanodollars($e->requiredNanos)->toDisplayUsd();
            $available = Money::fromNanodollars($e->availableNanos)->toDisplayUsd();
            return back()->withErrors([
                'balance' => "Insufficient credits: this generation can cost up to {$required}, but your balance is {$available}. Buy credits to continue.",
            ]);
        }
        SubmitGenerationToPixelforgeJob::dispatch($generation->id);
        return to_route('dashboard')->with('success', 'Generation queued! Any unused reserve will be refunded automatically once complete.');
    }

    public function refresh(
        Request $request,
        Generation $generation,
        PixelforgeClient $client,
        GenerationStateService $stateService,
    ): RedirectResponse {
        if ($generation->user_id !== $request->user()->id && ! $request->user()->is_admin) {
            abort(403);
        }
        if ($generation->status->isTerminal() || $generation->provider_generation_id === null) {
            return back();
        }
        try {
            $payload = $client->getGeneration($generation->provider_generation_id);
            $stateService->applyProviderPayload($payload, $generation);
        } catch (PixelforgeRetryableException | PixelforgeNonRetryableException $e) {
            return back()->with('error', "Could not refresh status from Pixelforge: {$e->getMessage()}");
        }
        return back()->with('success', 'Generation status refreshed from Pixelforge.');
    }
}
