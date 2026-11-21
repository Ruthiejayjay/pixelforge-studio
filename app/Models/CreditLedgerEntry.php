<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'generation_id',
        'type',
        'amount_nanos',
        'balance_after_nanos',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_nanos' => 'integer',
            'balance_after_nanos' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
