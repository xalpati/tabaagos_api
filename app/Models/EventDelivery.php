<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EventDelivery extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['details', 'push_token', 'payload', 'request_hash', 'idempotency_key'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'occurred_at' => 'datetime', 'acknowledged_at' => 'datetime', 'next_attempt_at' => 'datetime'];
    }
}
