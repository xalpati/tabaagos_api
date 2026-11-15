<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function register(Request $request)
    {
        $input = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'installation_id' => 'required|uuid',
        ]);

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'email' => strtolower(trim($input['email'])),
                'password' => Hash::make($input['password']),
            ]);

            $device = Device::query()->firstOrCreate(
                ['installation_id' => $input['installation_id']],
                ['user_id' => $user->id]
            );
            abort_unless($device->user_id === $user->id, 409);

            $device->update(['revoked_at' => null]);
            $user->tokens()->where('name', $device->id)->delete();
            $token = $user->createToken($device->id, ['mobile'], now()->addDays(config('tabapagos.token_days')));

            return response()->json([
                'access_token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'user_id' => $user->id,
                'device_id' => $device->id,
                'name' => $user->name,
                'email' => $user->email,
            ], 201);
        });
    }

    public function login(Request $request)
    {
        $input = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'installation_id' => 'required|uuid',
        ]);

        $user = User::where('email', strtolower(trim($input['email'])))->first();
        if (! $user || ! $user->password || ! Hash::check($input['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['El correo electrónico o la contraseña son incorrectos.'],
            ]);
        }

        return DB::transaction(function () use ($input, $user) {
            $device = Device::query()->firstOrCreate(
                ['installation_id' => $input['installation_id']],
                ['user_id' => $user->id]
            );
            // Una instalación no cambia de dueño mediante un nuevo inicio de sesión.
            abort_unless($device->user_id === $user->id, 409);

            $device->update(['revoked_at' => null]);
            $user->tokens()->where('name', $device->id)->delete();
            $token = $user->createToken($device->id, ['mobile'], now()->addDays(config('tabapagos.token_days')));

            return response()->json([
                'access_token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'user_id' => $user->id,
                'device_id' => $device->id,
                'name' => $user->name,
                'email' => $user->email,
            ]);
        });
    }

    public function me(Request $request)
    {
        return response()->json([
            'id' => $request->user()->id,
            'name' => $request->user()->name,
            'email' => $request->user()->email,
            'device_id' => $request->attributes->get('device')->id,
        ]);
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
