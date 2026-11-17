<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadController extends Controller
{
    /**
     * Calcula las métricas reales de descarga registradas en MySQL.
     */
    private function getDownloadStats(): array
    {
        $totalRegistrations = (int) DB::table('download_registrations')->count();
        $totalGrantDownloads = (int) DB::table('download_grants')->sum('download_count');

        // Cada permiso otorgado o descarga iniciada cuenta en el total acumulado
        $totalDownloads = max($totalRegistrations, $totalGrantDownloads);

        return [
            'total_downloads' => $totalDownloads,
            'total_registrations' => $totalRegistrations,
            'total_apk_serves' => $totalGrantDownloads,
        ];
    }

    public function meta(): JsonResponse
    {
        $release = DB::table('apk_releases')->where('is_active', true)->latest()->first();
        $stats = $this->getDownloadStats();

        if (! $release) {
            return response()->json([
                'available' => false,
                'message' => 'No hay una versión de APK publicada actualmente.',
                'stats' => $stats,
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
            'stats' => $stats,
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
                    'stats' => $this->getDownloadStats(),
                    'guide_url' => '/#guide',
                ], 200);
            }
        }

        $registrationId = (string) Str::uuid();
        $grantId = (string) Str::uuid();
        $token = Str::random(48);
        $expiresAt = now()->addMinutes(60);

        $cleanName = trim($validated['name']);
        $cleanEmail = strtolower(trim($validated['email']));
        $cleanBusiness = trim($validated['business_name'] ?? '') ?: null;
        $cleanPhone = trim($validated['phone'] ?? '') ?: null;
        $ipAddress = $request->ip();

        DB::transaction(function () use ($registrationId, $grantId, $release, $token, $expiresAt, $cleanName, $cleanEmail, $cleanBusiness, $cleanPhone, $validated, $requestId, $payloadHash, $ipAddress) {
            DB::table('download_registrations')->insert([
                'id' => $registrationId,
                'name' => $cleanName,
                'email' => $cleanEmail,
                'business_name' => $cleanBusiness,
                'phone' => $cleanPhone,
                'privacy_acknowledged' => true,
                'privacy_notice_version' => $validated['privacy_notice_version'] ?? '2026-11-15',
                'request_id' => $requestId,
                'payload_hash' => $payloadHash,
                'ip_address' => $ipAddress,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('download_grants')->insert([
                'id' => $grantId,
                'download_registration_id' => $registrationId,
                'apk_release_id' => $release->id,
                'token' => $token,
                'expires_at' => $expiresAt,
                'download_count' => 1,
                'downloaded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $stats = $this->getDownloadStats();

        // Notificar por correo electrónico al administrador sin bloquear la descarga en caso de error SMTP
        $this->sendRegistrationNotification([
            'registration_id' => $registrationId,
            'name' => $cleanName,
            'email' => $cleanEmail,
            'business_name' => $cleanBusiness ?? 'No especificado',
            'phone' => $cleanPhone ?? 'No especificado',
            'ip_address' => $ipAddress,
            'version_name' => $release->version_name,
            'registered_at' => now()->timezone('America/Mexico_City')->format('d/m/Y H:i:s'),
            'total_downloads' => $stats['total_downloads'],
        ]);

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
            'stats' => $stats,
            'guide_url' => '/#guide',
        ], 201);
    }

    /**
     * Envía los datos capturados en el formulario de descarga al correo del administrador.
     */
    private function sendRegistrationNotification(array $data): void
    {
        $recipient = env('DOWNLOAD_NOTIFICATION_EMAIL');
        if (! $recipient) {
            return;
        }

        try {
            $subject = "Nueva descarga de Tabapagos APK (#{$data['total_downloads']}) - {$data['name']}";

            $html = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;'>
                <div style='background: linear-gradient(135deg, #059669, #0d9488); color: #ffffff; padding: 20px 24px;'>
                    <h2 style='margin: 0; font-size: 20px;'>Nuevo Registro de Descarga — Tabapagos POS</h2>
                    <p style='margin: 6px 0 0 0; font-size: 13px; opacity: 0.9;'>Descarga acumulada #{$data['total_downloads']} • Versión {$data['version_name']}</p>
                </div>
                <div style='padding: 24px; background-color: #ffffff; color: #1e293b;'>
                    <p style='margin-top: 0; font-size: 14px;'>Se ha registrado una nueva solicitud para descargar el instalador APK de Android:</p>
                    <table style='width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 14px;'>
                        <tr>
                            <td style='padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-weight: bold; color: #475569; width: 40%;'>Nombre de contacto:</td>
                            <td style='padding: 10px 12px; border-bottom: 1px solid #f1f5f9; color: #0f172a;'>" . e($data['name']) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-weight: bold; color: #475569;'>Correo electrónico:</td>
                            <td style='padding: 10px 12px; border-bottom: 1px solid #f1f5f9; color: #0f172a;'><a href='mailto:" . e($data['email']) . "' style='color: #059669;'>" . e($data['email']) . "</a></td>
                        </tr>
                        <tr>
                            <td style='padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-weight: bold; color: #475569;'>Nombre del negocio:</td>
                            <td style='padding: 10px 12px; border-bottom: 1px solid #f1f5f9; color: #0f172a;'>" . e($data['business_name']) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-weight: bold; color: #475569;'>Teléfono / WhatsApp:</td>
                            <td style='padding: 10px 12px; border-bottom: 1px solid #f1f5f9; color: #0f172a;'>" . e($data['phone']) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-weight: bold; color: #475569;'>Fecha y hora (CDMX):</td>
                            <td style='padding: 10px 12px; border-bottom: 1px solid #f1f5f9; color: #0f172a;'>" . e($data['registered_at']) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-weight: bold; color: #475569;'>Dirección IP:</td>
                            <td style='padding: 10px 12px; border-bottom: 1px solid #f1f5f9; color: #64748b;'>" . e($data['ip_address']) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 10px 12px; font-weight: bold; color: #475569;'>Folio de registro:</td>
                            <td style='padding: 10px 12px; color: #64748b; font-family: monospace; font-size: 12px;'>" . e($data['registration_id']) . "</td>
                        </tr>
                    </table>
                </div>
                <div style='background-color: #f8fafc; padding: 14px 24px; font-size: 12px; color: #64748b; text-align: center; border-top: 1px solid #e2e8f0;'>
                    Notificación automática del servicio de distribución de Tabapagos API.
                </div>
            </div>";

            Mail::html($html, function ($message) use ($recipient, $subject, $data) {
                $message->to($recipient)
                    ->replyTo($data['email'], $data['name'])
                    ->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el correo de notificación de descarga: ' . $e->getMessage());
        }
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
