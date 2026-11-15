<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\User;
use App\Services\BankActivation;
use App\Services\Idempotency;
use App\Services\PeyClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BankAccountController
{
    public function show(Request $r)
    {
        $bank = BankAccount::query()->where('user_id', $r->user()->id)->first();

        return response()->json(['bank_account' => $bank ? $this->result($bank) : null]);
    }

    public function store(Request $r, PeyClient $pey)
    {
        $data = $r->validate(['alias' => 'required|string|max:255', 'name' => 'required|string|max:255', 'clabe' => ['required', 'regex:/^[0-9]{18}$/D'], 'entity' => 'required|in:PERSON,BUSINESS']);
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += ((int) $data['clabe'][$i] * [3, 7, 1][$i % 3]) % 10;
        }
        abort_unless((10 - $sum % 10) % 10 === (int) $data['clabe'][17], 422);
        $key = Idempotency::key($r);
        $hash = Idempotency::hash($data);
        $pey->available();
        [$bank,$created] = DB::transaction(function () use ($r, $data, $key, $hash) {
            User::query()->whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $bank = BankAccount::query()->where('user_id', $r->user()->id)->first();
            if ($bank) {
                abort_unless($bank->idempotency_key === $key && hash_equals($bank->request_hash, $hash), 409);

                return [$bank, false];
            }

            return [BankAccount::query()->create(['user_id' => $r->user()->id, 'status' => 'SUBMITTING', 'last_four' => substr($data['clabe'], -4), 'details' => $data, 'idempotency_key' => $key, 'request_hash' => $hash]), true];
        });
        if ($created) {
            try {
                $response = $pey->call('POST', '/bank-account', $data);
                if (! is_string($response['id'] ?? null) || ! in_array($response['codiStatus'] ?? '', ['PENDING', 'PROCESSING', 'ACTIVE', 'ERROR'], true)) {
                    throw new \RuntimeException('INVALID_RESPONSE');
                }
                DB::transaction(function () use ($bank, $response) {
                    $bank = BankAccount::query()->whereKey($bank->id)->lockForUpdate()->firstOrFail();
                    $bank->update(['provider_id' => $response['id'], 'status' => $response['codiStatus']]);
                    if ($bank->status === 'ACTIVE') {
                        app(BankActivation::class)->activate($bank);
                    }
                });
            } catch (\Throwable) {
                BankAccount::query()->whereKey($bank->id)->where('status', 'SUBMITTING')->update(['status' => 'UNKNOWN']);
            }
        }

        return response()->json($this->result($bank->fresh()), $created ? 201 : 200);
    }

    public function refresh(Request $r, PeyClient $pey)
    {
        $bank = BankAccount::query()->where('user_id', $r->user()->id)->firstOrFail();
        if ($bank->status === 'ACTIVE' || ! $bank->provider_id) {
            return response()->json($this->result($bank));
        }

        return Cache::lock('bank-refresh:'.$bank->id, 30)->get(function () use ($bank, $pey) {
            $bank->refresh();
            if ($bank->status === 'ACTIVE') {
                return response()->json($this->result($bank));
            }
            try {
                $result = $pey->call('GET', '/bank-account/'.rawurlencode($bank->provider_id));
            } catch (\Throwable) {
                abort(503);
            }
            abort_unless(($result['id'] ?? null) === $bank->provider_id && in_array($result['codiStatus'] ?? '', ['PENDING', 'PROCESSING', 'ACTIVE', 'ERROR'], true), 502);
            DB::transaction(function () use ($bank, $result) {
                $bank = BankAccount::query()->whereKey($bank->id)->lockForUpdate()->firstOrFail();
                if ($bank->status === 'ACTIVE') {
                    return;
                }
                $bank->update(['status' => $result['codiStatus']]);
                if ($bank->status === 'ACTIVE') {
                    app(BankActivation::class)->activate($bank);
                }
            });

            return response()->json($this->result($bank->fresh()));
        }) ?? response()->json(['code' => 'REFRESH_IN_PROGRESS'], 202);
    }

    private function result(BankAccount $bank): array
    {
        return ['id' => $bank->id, 'status' => $bank->status, 'clabe_last_four' => $bank->last_four, 'activated_at' => $bank->activated_at];
    }
}
