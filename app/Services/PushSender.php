<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Http;

class PushSender
{
    public function send(string $token, array $envelope): bool
    {
        $credentials = config('tabapagos.fcm_credentials_path');
        $project = config('tabapagos.fcm_project_id');
        if (! $credentials || ! is_file($credentials) || ! $project) {
            throw new \RuntimeException('PUSH_NOT_CONFIGURED');
        }
        $jws = app(EventSigner::class)->sign($envelope);
        $body = ['message' => ['token' => $token, 'data' => ['event' => $jws], 'android' => ['priority' => 'high']]];
        if (strlen(json_encode($body, JSON_THROW_ON_ERROR)) > 4096) {
            throw new \RuntimeException('EVENT_TOO_LARGE');
        }
        $auth = new ServiceAccountCredentials('https://www.googleapis.com/auth/firebase.messaging', json_decode(file_get_contents($credentials), true, 512, JSON_THROW_ON_ERROR));
        $access = $auth->fetchAuthToken();
        $response = Http::withToken($access['access_token'])->connectTimeout(5)->timeout(15)->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($project).'/messages:send', $body);
        if ($response->successful()) {
            return true;
        }
        foreach ($response->json('error.details') ?? [] as $detail) {
            if (($detail['errorCode'] ?? null) === 'UNREGISTERED') {
                return false;
            }
        }
        throw new \RuntimeException('PUSH_TEMPORARILY_FAILED');
    }
}
