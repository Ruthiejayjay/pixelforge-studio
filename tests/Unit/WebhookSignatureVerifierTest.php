<?php

namespace Tests\Unit;

use App\Services\Pixelforge\WebhookSignatureVerifier;
use Tests\TestCase;

class WebhookSignatureVerifierTest extends TestCase
{
    public function test_accepts_valid_hmac_signature_within_tolerance(): void
    {
        $verifier = new WebhookSignatureVerifier;
        $secret = 'whsec_test_secret_123';
        $payload = '{"id":"evt_123","type":"ping"}';
        $now = 1_762_265_000;
        $sig = hash_hmac('sha256', "{$now}.{$payload}", $secret);

        $this->assertTrue($verifier->verify($payload, "t={$now},v1={$sig}", $secret, 300, $now + 10));
    }

    public function test_rejects_tampered_payload_wrong_secret_and_stale_timestamp(): void
    {
        $verifier = new WebhookSignatureVerifier;
        $secret = 'whsec_test_secret_123';
        $payload = '{"id":"evt_123","type":"ping"}';
        $timestamp = 1_762_265_000;
        $sig = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        $header = "t={$timestamp},v1={$sig}";

        // Tampered payload
        $this->assertFalse($verifier->verify('{"id":"evt_999","type":"ping"}', $header, $secret, 300, $timestamp));

        // Wrong secret
        $this->assertFalse($verifier->verify($payload, $header, 'whsec_wrong', 300, $timestamp));

        // Expired (>300s old)
        $this->assertFalse($verifier->verify($payload, $header, $secret, 300, $timestamp + 301));

        // Missing or malformed header
        $this->assertFalse($verifier->verify($payload, null, $secret, 300, $timestamp));
        $this->assertFalse($verifier->verify($payload, 'malformed-header', $secret, 300, $timestamp));
    }
}