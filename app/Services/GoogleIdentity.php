<?php

namespace App\Services;

use Google\Client;

class GoogleIdentity
{
    public function verify(string $token): array
    {
        $audience = config('tabapagos.google_client_id');
        abort_unless($audience, 503);
        try {
            $claims = (new Client(['client_id' => $audience]))->verifyIdToken($token);
        } catch (\Throwable) {
            abort(401);
        }
        abort_unless($claims && isset($claims['sub']) && in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true) && ($claims['aud'] ?? null) === $audience, 401);

        return $claims;
    }
}
