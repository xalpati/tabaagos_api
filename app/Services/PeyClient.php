<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PeyClient
{
    public function available(): void
    {
        abort_unless(config('tabapagos.pey_enabled') && config('tabapagos.pey_key') && config('tabapagos.pey_key_id'), 503);
    }

    public function call(string $method, string $path, array $data = []): array
    {
        $this->available();
        // Los POST no se reintentan: una respuesta perdida puede ocultar un cobro creado.
        $response = Http::baseUrl('https://api.pey.mx')->withToken(config('tabapagos.pey_key'))->acceptJson()->connectTimeout(5)->timeout(20)->send($method, $path, $method === 'GET' ? [] : ['json' => $data]);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new \RuntimeException('PROVIDER_UNCERTAIN');
        }

        return $response->json();
    }
}
