<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Device;
use App\Models\EventDelivery;
use Illuminate\Support\Str;

class BankActivation
{
    public function activate(BankAccount $bank): void
    {
        if ($bank->activated_at) {
            return;
        }
        $bank->update(['status' => 'ACTIVE', 'activated_at' => now()]);
        $eventId = (string) Str::uuid();
        foreach (Device::query()->where('user_id', $bank->user_id)->whereNull('revoked_at')->cursor() as $device) {
            EventDelivery::query()->create(['event_id' => $eventId, 'device_id' => $device->id, 'type' => 'bank.account.activated', 'payload' => ['bank_account_id' => $bank->id, 'codi_status' => 'ACTIVE'], 'occurred_at' => $bank->activated_at, 'next_attempt_at' => now()]);
        }
    }
}
