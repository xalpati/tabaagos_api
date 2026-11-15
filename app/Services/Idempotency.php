<?php

namespace App\Services;

use Illuminate\Http\Request;

class Idempotency
{
    public static function key(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $key), 422);

        return $key;
    }

    public static function hash(array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
