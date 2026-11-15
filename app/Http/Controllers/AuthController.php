<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\User;
use App\Services\GoogleIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController
{
    public function login(Request $request, GoogleIdentity $identity)
    {
        $input = $request->validate(['id_token' => 'required|string|max:8192', 'installation_id' => 'required|uuid']);
        $claims = $identity->verify($input['id_token']);

        return DB::transaction(function () use ($input, $claims) {
            $user = User::query()->firstOrCreate(['google_sub' => $claims['sub']], ['email' => $claims['email'] ?? null, 'name' => $claims['name'] ?? null]);
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $device = Device::query()->firstOrCreate(['installation_id' => $input['installation_id']], ['user_id' => $user->id]);
            // Una instalación no cambia de dueño mediante un nuevo inicio de sesión.
            abort_unless($device->user_id === $user->id, 409);
            $device->update(['revoked_at' => null]);
            $user->tokens()->where('name', $device->id)->delete();
            $token = $user->createToken($device->id, ['mobile'], now()->addDays(config('tabapagos.token_days')));

            return response()->json(['access_token' => $token->plainTextToken, 'token_type' => 'Bearer', 'user_id' => $user->id, 'device_id' => $device->id]);
        });
    }

    public function me(Request $request)
    {
        return response()->json(['id' => $request->user()->id, 'name' => $request->user()->name, 'email' => $request->user()->email, 'device_id' => $request->attributes->get('device')->id]);
    }

    public function logout(Request $request)
    {
        DB::transaction(function () use ($request) {
            $device = $request->attributes->get('device');
            $device->update(['push_token' => null, 'revoked_at' => now()]);
            $request->user()->tokens()->where('name', $device->id)->delete();
        });

        return response()->noContent();
    }
}
