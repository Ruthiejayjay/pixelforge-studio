<?php

namespace App\Support;

use App\Models\Generation;

class GenerationPresenter
{
    public static function toArray(Generation $generation): array
    {
        $minEst = Money::fromNanodollars($generation->estimated_min_user_cost_nanos);
        $expectedEst = Money::fromNanodollars($generation->estimated_expected_user_cost_nanos);
        $maxEst = Money::fromNanodollars($generation->estimated_max_user_cost_nanos);
        $reserved = Money::fromNanodollars($generation->reserved_user_cost_nanos);
        $actualUser = $generation->actual_user_cost_nanos !== null
            ? Money::fromNanodollars($generation->actual_user_cost_nanos)
            : null;
        $actualProvider = $generation->actual_provider_cost_nanos !== null
            ? Money::fromNanodollars($generation->actual_provider_cost_nanos)
            : null;
        return [
            'uuid' => $generation->uuid,
            'provider_generation_id' => $generation->provider_generation_id,
            'status' => $generation->status->value,
            'is_active' => $generation->status->isActive(),
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images ?? [],
            'provider_rates' => $generation->provider_rates,
            'provider_usage' => $generation->provider_usage,
            'estimated_min_user_cost_nanos' => $minEst->nanos,
            'estimated_expected_user_cost_nanos' => $expectedEst->nanos,
            'estimated_max_user_cost_nanos' => $maxEst->nanos,
            'reserved_user_cost_nanos' => $reserved->nanos,
            'estimated_min_display' => $minEst->toDisplayUsd(2, 6),
            'estimated_expected_display' => $expectedEst->toDisplayUsd(2, 6),
            'estimated_max_display' => $maxEst->toDisplayUsd(2, 6),
            'reserved_display' => $reserved->toDisplayUsd(2, 6),
            'actual_provider_cost_nanos' => $actualProvider?->nanos,
            'actual_provider_cost_exact' => $actualProvider?->toExactUsdString(),
            'actual_user_cost_nanos' => $actualUser?->nanos,
            'actual_user_cost_display' => $actualUser?->toDisplayUsd(2, 6),
            'actual_user_cost_exact' => $actualUser?->toExactUsdString(),
            'image_url' => $generation->image_url,
            'image_url_expires_at' => $generation->image_url_expires_at?->toIso8601String(),
            'image_url_expired' => $generation->image_url_expires_at?->isPast() ?? false,
            'error_code' => $generation->error_code,
            'error_message' => $generation->error_message,
            'created_at' => $generation->created_at?->toIso8601String(),
            'completed_at' => $generation->provider_completed_at?->toIso8601String(),
        ];
    }
}
