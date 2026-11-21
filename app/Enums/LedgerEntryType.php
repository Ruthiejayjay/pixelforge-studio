<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    case WelcomeBonus = 'welcome_bonus';
    case Purchase = 'purchase';
    case GenerationHold = 'generation_hold';
    case GenerationSettlementRefund = 'generation_settlement_refund';
    case GenerationSettlementAdjustment = 'generation_settlement_adjustment';
    case GenerationFailureRefund = 'generation_failure_refund';

    public function label(): string
    {
        return match ($this) {
            self::WelcomeBonus => 'Welcome Bonus',
            self::Purchase => 'Credit Purchase',
            self::GenerationHold => 'Generation Reserve',
            self::GenerationSettlementRefund => 'Unused Reserve Refund',
            self::GenerationSettlementAdjustment => 'Settlement Adjustment',
            self::GenerationFailureRefund => 'Failed Generation Refund',
        };
    }
}