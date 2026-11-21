<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Generation extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'idempotency_key',
        'provider_generation_id',
        'status',
        'prompt',
        'size',
        'quality',
        'reference_images',
        'provider_rates',
        'provider_usage',
        'estimated_min_user_cost_nanos',
        'estimated_expected_user_cost_nanos',
        'estimated_max_user_cost_nanos',
        'reserved_user_cost_nanos',
        'actual_provider_cost_nanos',
        'actual_user_cost_nanos',
        'image_url',
        'image_url_expires_at',
        'error_code',
        'error_message',
        'provider_created_at',
        'provider_started_at',
        'provider_completed_at',
        'completed_utc_date',
    ];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'reference_images' => 'array',
            'provider_rates' => 'array',
            'provider_usage' => 'array',
            'estimated_min_user_cost_nanos' => 'integer',
            'estimated_expected_user_cost_nanos' => 'integer',
            'estimated_max_user_cost_nanos' => 'integer',
            'reserved_user_cost_nanos' => 'integer',
            'actual_provider_cost_nanos' => 'integer',
            'actual_user_cost_nanos' => 'integer',
            'image_url_expires_at' => 'datetime',
            'provider_created_at' => 'datetime',
            'provider_started_at' => 'datetime',
            'provider_completed_at' => 'datetime',
            'completed_utc_date' => 'date:Y-m-d',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }
}
