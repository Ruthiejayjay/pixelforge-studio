<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgePricingService;
use App\Support\GenerationPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudioController extends Controller
{
    public function index(Request $request, PixelforgePricingService $pricingService): Response
    {
        $user = $request->user();
        $generations = Generation::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->paginate(15)
            ->through(static fn(Generation $generation) => GenerationPresenter::toArray($generation));
        $hasActiveGenerations = Generation::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                GenerationStatus::Submitting,
                GenerationStatus::Queued,
                GenerationStatus::Processing,
            ])
            ->exists();
        return Inertia::render('studio/Index', [
            'pricing' => $pricingService->getPricing(),
            'markupPercent' => (int) config('services.pixelforge.markup_percent', 25),
            'generations' => $generations,
            'hasActiveGenerations' => $hasActiveGenerations,
        ]);
    }
}
