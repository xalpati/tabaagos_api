<?php

namespace Tests\Feature;

use App\Services\EventSigner;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Tests\TestCase;

class EventSignatureTest extends TestCase
{
    public function test_event_signature_verifies_recipient_and_rejects_tampering(): void
    {
        $config = tempnam(sys_get_temp_dir(), 'tabapagos-openssl-test-');
        file_put_contents($config, "[req]\ndistinguished_name = req_dn\n[req_dn]\n");
        $key = openssl_pkey_new(['config' => $config, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private, null, ['config' => $config]);
        $public = openssl_pkey_get_details($key)['key'];
        $path = tempnam(sys_get_temp_dir(), 'tabapagos-jws-test-');
        try {
            file_put_contents($path, $private);
            config(['tabapagos.signing_key_path' => $path, 'tabapagos.signing_kid' => 'test-key-1']);
            $jws = app(EventSigner::class)->sign(['device_id' => 'device-1', 'payload' => ['amount_minor' => 15000]]);
            $decoded = JWT::decode($jws, new Key($public, 'RS256'));
            $this->assertSame('device-1', $decoded->device_id);
            $this->assertSame(15000, $decoded->payload->amount_minor);
            $parts = explode('.', $jws);
            $parts[1] = rtrim(strtr(base64_encode('{"device_id":"foreign-device"}'), '+/', '-_'), '=');
            $this->expectException(SignatureInvalidException::class);
            JWT::decode(implode('.', $parts), new Key($public, 'RS256'));
        } finally {
            unlink($path);
            unlink($config);
        }
    }
}
