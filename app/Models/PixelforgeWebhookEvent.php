<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PixelforgeWebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'event_type',
        'provider_generation_id',
        'delivery_attempt',
        'payload',
        'received_at',
    ];
    protected function casts(): array
    {
        return [
            'delivery_attempt' => 'integer',
            'payload' => 'array',
            'received_at' => 'datetime',
        ];
    }
}
