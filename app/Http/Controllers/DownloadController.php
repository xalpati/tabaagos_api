<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadController extends Controller
{
    public function meta(): JsonResponse
    {
        $release = DB::table('apk_releases')->where('is_active', true)->latest()->first();

        if (! $release) {
            return response()->json([
                'available' => false,
                'message' => 'No hay una versión de APK publicada actualmente.',
            ], 404);
        }

        return response()->json([
            'available' => true,
            'release' => [
                'id' => $release->id,
                'version_name' => $release->version_name,
                'version_code' => $release->version_code,
                'package_name' => $release->package_name,
                'min_sdk' => $release->min_sdk,
                'min_android' => 'Android 7.0 (API ' . $release->min_sdk . ')+',
                'file_size_bytes' => (int) $release->file_size_bytes,
                'file_size_human' => round($release->file_size_bytes / (1024 * 1024), 1) . ' MB',
                'file_sha256' => $release->file_sha256,
            ],
            'guide_url' => '/#guide',
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'email' => 'required|email|max:254',
            'business_name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:20',
            'privacy_acknowledged' => 'required|boolean',
            'privacy_notice_version' => 'nullable|string|max:20',
            'request_id' => 'nullable|string|max:128',
        ]);

        if (! $validated['privacy_acknowledged']) {
            return response()->json(['message' => 'Es necesario aceptar el aviso de privacidad.'], 422);
        }

        $requestId = $validated['request_id'] ?? (string) Str::uuid();
        $payloadHash = hash('sha256', json_encode([
            'name' => trim($validated['name']),
            'email' => strtolower(trim($validated['email'])),
            'business_name' => trim($validated['business_name'] ?? ''),
            'phone' => trim($validated['phone'] ?? ''),
        ]));

        $release = DB::table('apk_releases')->where('is_active', true)->latest()->first();
        if (! $release) {
            return response()->json([
                'message' => 'La descarga de la APK aún no está disponible para esta versión.',
            ], 503);
        }

        // Idempotency check
        $existing = DB::table('download_registrations')
            ->where('request_id', $requestId)
            ->where('payload_hash', $payloadHash)
            ->first();

        if ($existing) {
            $grant = DB::table('download_grants')
                ->where('download_registration_id', $existing->id)
                ->where('apk_release_id', $release->id)
                ->where('expires_at', '>', now())
                ->latest()
                ->first();

            if ($grant) {
                return response()->json([
                    'registration_id' => $existing->id,
                    'download_url' => '/api/v1/downloads/apk/' . $grant->token,
                    'direct_apk_url' => '/downloads/tabapagos-release.apk',
                    'expires_at' => $grant->expires_at,
                    'release' => [
                        'version_name' => $release->version_name,
                        'file_size_bytes' => (int) $release->file_size_bytes,
                        'file_size_human' => round($release->file_size_bytes / (1024 * 1024), 1) . ' MB',
                        'file_sha256' => $release->file_sha256,
                    ],
                    'guide_url' => '/#guide',
                ], 200);
            }
        }

        $registrationId = (string) Str::uuid();
        $grantId = (string) Str::uuid();
        $token = Str::random(48);
        $expiresAt = now()->addMinutes(60);

        DB::transaction(function () use ($registrationId, $grantId, $release, $token, $expiresAt, $validated, $requestId, $payloadHash, $request) {
            DB::table('download_registrations')->insert([
                'id' => $registrationId,
                'name' => trim($validated['name']),
                'email' => strtolower(trim($validated['email'])),
                'business_name' => trim($validated['business_name'] ?? '') ?: null,
                'phone' => trim($validated['phone'] ?? '') ?: null,
                'privacy_acknowledged' => true,
                'privacy_notice_version' => $validated['privacy_notice_version'] ?? '2026-11-15',
                'request_id' => $requestId,
                'payload_hash' => $payloadHash,
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('download_grants')->insert([
                'id' => $grantId,
                'download_registration_id' => $registrationId,
                'apk_release_id' => $release->id,
                'token' => $token,
                'expires_at' => $expiresAt,
                'download_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'registration_id' => $registrationId,
            'download_url' => '/api/v1/downloads/apk/' . $token,
            'direct_apk_url' => '/downloads/tabapagos-release.apk',
            'expires_at' => $expiresAt->toIso8601String(),
            'release' => [
                'version_name' => $release->version_name,
                'file_size_bytes' => (int) $release->file_size_bytes,
                'file_size_human' => round($release->file_size_bytes / (1024 * 1024), 1) . ' MB',
                'file_sha256' => $release->file_sha256,
            ],
            'guide_url' => '/#guide',
        ], 201);
    }

    public function download(string $token): BinaryFileResponse|JsonResponse
    {
        $grant = DB::table('download_grants')->where('token', $token)->first();

        if (! $grant) {
            return response()->json(['message' => 'Token de descarga no válido o inexistente.'], 404);
        }

        if (now()->isAfter($grant->expires_at)) {
            return response()->json(['message' => 'El enlace de descarga ha expirado. Por favor solicita uno nuevo.'], 410);
        }

        $release = DB::table('apk_releases')->where('id', $grant->apk_release_id)->first();
        if (! $release) {
            return response()->json(['message' => 'Versión no encontrada.'], 404);
        }

        // Incrementar contador
        DB::table('download_grants')->where('id', $grant->id)->update([
            'download_count' => $grant->download_count + 1,
            'downloaded_at' => now(),
            'updated_at' => now(),
        ]);

        $possiblePaths = [
            base_path('../downloads/tabapagos-release.apk'),
            dirname(base_path()) . '/downloads/tabapagos-release.apk',
            $release->file_path,
            storage_path('app/releases/tabapagos-release.apk'),
            public_path('downloads/tabapagos-release.apk'),
            base_path('../tabapagos/build/app/outputs/flutter-apk/app-release.apk'),
        ];

        $targetPath = null;
        foreach ($possiblePaths as $p) {
            if ($p && file_exists($p)) {
                $targetPath = $p;
                break;
            }
        }

        if (! $targetPath) {
            return response()->json(['message' => 'El archivo físico del instalador no está disponible en este momento.'], 404);
        }

        return response()->download($targetPath, 'tabapagos-release.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
