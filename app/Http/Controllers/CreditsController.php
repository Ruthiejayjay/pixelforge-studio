<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Requests\PurchaseCreditsRequest;
use App\Models\CreditLedgerEntry;
use App\Models\Generation;
use App\Services\Billing\CreditLedgerService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $entries = CreditLedgerEntry::query()
            ->with('generation:id,uuid')
            ->where('user_id', $user->id)
            ->latest('id')
            ->paginate(25)
            ->through(static function (CreditLedgerEntry $entry): array {
                $amount = Money::fromNanodollars($entry->amount_nanos);
                $balanceAfter = Money::fromNanodollars($entry->balance_after_nanos);

                return [
                    'id' => $entry->id,
                    'type' => $entry->type->value,
                    'type_label' => $entry->type->label(),
                    'amount_nanos' => $amount->nanos,
                    'amount_display' => $amount->toDisplayUsd(2, 6),
                    'amount_exact' => $amount->toExactUsdString(),
                    'balance_after_nanos' => $balanceAfter->nanos,
                    'balance_after_display' => $balanceAfter->toDisplayUsd(2, 6),
                    'description' => $entry->description,
                    'generation_uuid' => $entry->generation?->uuid,
                    'created_at' => $entry->created_at?->toIso8601String(),
                ];
            });

        $totalSpentNanos = (int) Generation::query()
            ->where('user_id', $user->id)
            ->where('status', GenerationStatus::Succeeded)
            ->sum('actual_user_cost_nanos');

        $activeHoldNanos = (int) Generation::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                GenerationStatus::Submitting,
                GenerationStatus::Queued,
                GenerationStatus::Processing,
            ])
            ->sum('reserved_user_cost_nanos');

        return Inertia::render('Credits/Index', [
            'summary' => [
                'balance_nanos' => $user->balance_nanos,
                'balance_display' => Money::fromNanodollars($user->balance_nanos)->toDisplayUsd(2, 6),
                'balance_exact' => Money::fromNanodollars($user->balance_nanos)->toExactUsdString(),
                'total_spent_display' => Money::fromNanodollars($totalSpentNanos)->toDisplayUsd(2, 6),
                'active_hold_display' => Money::fromNanodollars($activeHoldNanos)->toDisplayUsd(2, 6),
            ],
            'packs' => ['1.00', '5.00', '10.00', '25.00'],
            'entries' => $entries,
        ]);
    }

    public function purchase(PurchaseCreditsRequest $request, CreditLedgerService $ledger): RedirectResponse
    {
        $money = Money::fromDecimalString((string) $request->validated('amount_usd'));
        $ledger->purchaseCredits($request->user(), $money->nanos);

        return back()->with('success', "Added {$money->toDisplayUsd()} to your credit balance.");
    }
}
