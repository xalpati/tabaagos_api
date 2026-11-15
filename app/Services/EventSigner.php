<?php

namespace App\Services;

use Firebase\JWT\JWT;

class EventSigner
{
    public function sign(array $envelope): string
    {
        $path = config('tabapagos.signing_key_path');
        $kid = config('tabapagos.signing_kid');
        if (! $path || ! is_file($path) || ! $kid) {
            throw new \RuntimeException('SIGNING_NOT_CONFIGURED');
        }

        return JWT::encode($envelope, file_get_contents($path), 'RS256', $kid);
    }
}
