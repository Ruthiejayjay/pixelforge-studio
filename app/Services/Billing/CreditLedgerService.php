<?php

namespace App\Services\Billing;

use App\Enums\LedgerEntryType;
use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditLedgerEntry;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreditLedgerService
{
    public function grantWelcomeBonus(User $user): ?CreditLedgerEntry
    {
        $amountNanos = (int) config('services.pixelforge.welcome_credits_nanos', 2_000_000_000);

        if ($amountNanos <= 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $amountNanos): CreditLedgerEntry {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $newBalance = $lockedUser->balance_nanos + $amountNanos;
            $lockedUser->update(['balance_nanos' => $newBalance]);

            return CreditLedgerEntry::create([
                'user_id' => $lockedUser->id,
                'generation_id' => null,
                'type' => LedgerEntryType::WelcomeBonus,
                'amount_nanos' => $amountNanos,
                'balance_after_nanos' => $newBalance,
                'description' => 'Welcome credits for new account',
            ]);
        });
    }

    public function purchaseCredits(User $user, int $amountNanos, ?string $description = null): CreditLedgerEntry
    {
        if ($amountNanos <= 0) {
            throw new InvalidArgumentException('Purchase amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amountNanos, $description): CreditLedgerEntry {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $newBalance = $lockedUser->balance_nanos + $amountNanos;
            $lockedUser->update(['balance_nanos' => $newBalance]);

            $display = Money::fromNanodollars($amountNanos)->toDisplayUsd();

            return CreditLedgerEntry::create([
                'user_id' => $lockedUser->id,
                'generation_id' => null,
                'type' => LedgerEntryType::Purchase,
                'amount_nanos' => $amountNanos,
                'balance_after_nanos' => $newBalance,
                'description' => $description ?? "Added {$display} in credits (simulated purchase)",
            ]);
        });
    }

    public function placeHold(User $user, Generation $generation, int $amountNanos): CreditLedgerEntry
    {
        if ($amountNanos <= 0) {
            throw new InvalidArgumentException('Hold amount must be positive.');
        }

        return DB::transaction(function () use ($user, $generation, $amountNanos): CreditLedgerEntry {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($lockedUser->balance_nanos < $amountNanos) {
                throw new InsufficientCreditsException(
                    requiredNanos: $amountNanos,
                    availableNanos: $lockedUser->balance_nanos,
                );
            }

            $newBalance = $lockedUser->balance_nanos - $amountNanos;
            $lockedUser->update(['balance_nanos' => $newBalance]);

            return CreditLedgerEntry::create([
                'user_id' => $lockedUser->id,
                'generation_id' => $generation->id,
                'type' => LedgerEntryType::GenerationHold,
                'amount_nanos' => -$amountNanos,
                'balance_after_nanos' => $newBalance,
                'description' => "Max cost reserve for generation {$generation->uuid}",
            ]);
        });
    }

    /**
     * Idempotently settle a succeeded generation against its reserved hold.
     * Net user balance change across all entries for this generation = exact -actualUserCostNanos.
     */
    public function settleGenerationSuccess(Generation $generation, int $actualUserCostNanos): void
    {
        $reservedNanos = $generation->reserved_user_cost_nanos;
        $delta = $reservedNanos - $actualUserCostNanos;

        if ($delta === 0) {
            return;
        }

        $entryType = $delta > 0
            ? LedgerEntryType::GenerationSettlementRefund
            : LedgerEntryType::GenerationSettlementAdjustment;

        DB::transaction(function () use ($generation, $delta, $entryType): void {
            $alreadySettled = CreditLedgerEntry::query()
                ->where('generation_id', $generation->id)
                ->whereIn('type', [
                    LedgerEntryType::GenerationSettlementRefund,
                    LedgerEntryType::GenerationSettlementAdjustment,
                    LedgerEntryType::GenerationFailureRefund,
                ])
                ->exists();

            if ($alreadySettled) {
                return;
            }

            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($generation->user_id)->lockForUpdate()->firstOrFail();

            $newBalance = $lockedUser->balance_nanos + $delta;
            $lockedUser->update(['balance_nanos' => $newBalance]);

            $description = $delta > 0
                ? "Unused reserve released after generation {$generation->uuid} succeeded"
                : "Final cost adjustment after generation {$generation->uuid} succeeded";

            CreditLedgerEntry::create([
                'user_id' => $lockedUser->id,
                'generation_id' => $generation->id,
                'type' => $entryType,
                'amount_nanos' => $delta,
                'balance_after_nanos' => $newBalance,
                'description' => $description,
            ]);
        });
    }

    /**
     * Idempotently refund 100% of the reserved hold when a generation fails (net cost = $0).
     */
    public function refundGenerationFailure(Generation $generation): void
    {
        $reservedNanos = $generation->reserved_user_cost_nanos;

        if ($reservedNanos <= 0) {
            return;
        }

        DB::transaction(function () use ($generation, $reservedNanos): void {
            $alreadySettled = CreditLedgerEntry::query()
                ->where('generation_id', $generation->id)
                ->whereIn('type', [
                    LedgerEntryType::GenerationSettlementRefund,
                    LedgerEntryType::GenerationSettlementAdjustment,
                    LedgerEntryType::GenerationFailureRefund,
                ])
                ->exists();

            if ($alreadySettled) {
                return;
            }

            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($generation->user_id)->lockForUpdate()->firstOrFail();

            $newBalance = $lockedUser->balance_nanos + $reservedNanos;
            $lockedUser->update(['balance_nanos' => $newBalance]);

            CreditLedgerEntry::create([
                'user_id' => $lockedUser->id,
                'generation_id' => $generation->id,
                'type' => LedgerEntryType::GenerationFailureRefund,
                'amount_nanos' => $reservedNanos,
                'balance_after_nanos' => $newBalance,
                'description' => "Full refund for failed generation {$generation->uuid}",
            ]);
        });
    }
}