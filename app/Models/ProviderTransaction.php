<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProviderTransaction extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['details', 'push_token', 'payload', 'request_hash', 'idempotency_key'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'amount_minor' => 'integer'];
    }
}
