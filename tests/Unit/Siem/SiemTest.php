<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Tests for the SIEM surface: the RFC 5424 syslog frame shape (byte-parity body
 * with the webhook + export), webhook signing + rotation-grace verification, the
 * HTTP-JSON forwarder's https-only guard, the circuit breaker's threshold +
 * cooldown, and the S3 forwarder's SDK-absent no-op.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\auditkit\engine\Canonicalizer;
use craftpulse\auditkit\siem\CircuitBreaker;
use craftpulse\auditkit\siem\HttpJsonForwarder;
use craftpulse\auditkit\siem\S3Forwarder;
use craftpulse\auditkit\siem\SyslogFramer;
use craftpulse\auditkit\siem\WebhookSigner;

it('frames a canonical body as RFC 5424 local0.notice', function() {
    $body = Canonicalizer::canonicalize(['event' => 'login', 'outcome' => 'success']);
    $frame = (new SyslogFramer())->frame($body, 'ledger', 'audit-log');

    // <133> = local0(16)*8 + notice(5); version 1; body appears verbatim.
    expect($frame)->toStartWith('<133>1 ')
        ->and($frame)->toContain(' ledger ')
        ->and($frame)->toEndWith($body);
});

it('signs and verifies a webhook, honouring rotation grace', function() {
    $signer = new WebhookSigner();
    $body = Canonicalizer::canonicalize(['event' => 'login']);

    $sig = $signer->sign('1700000000', 'evt-1', $body, 'current-secret');

    expect($sig)->toStartWith('sha256=')
        ->and($signer->verify($sig, '1700000000', 'evt-1', $body, 'current-secret'))->toBeTrue()
        // A signature under the previous secret verifies during the grace window.
        ->and($signer->verify(
            $signer->sign('1700000000', 'evt-1', $body, 'old-secret'),
            '1700000000',
            'evt-1',
            $body,
            'current-secret',
            'old-secret',
        ))->toBeTrue()
        // Wrong secret and no grace: rejected.
        ->and($signer->verify($sig, '1700000000', 'evt-1', $body, 'different-secret'))->toBeFalse();
});

it('refuses to forward over plaintext http', function() {
    $result = (new HttpJsonForwarder())->forward('http://insecure.test/hook', '{}');

    expect($result->success)->toBeFalse()
        ->and($result->errorMessage)->toContain('https://');
});

it('opens the circuit after the failure threshold and clears on success', function() {
    $breaker = new CircuitBreaker(threshold: 3, cooldownSeconds: 300);
    $key = 'forwarder-' . uniqid();

    expect($breaker->isOpen($key))->toBeFalse()
        ->and($breaker->recordFailure($key))->toBeFalse()
        ->and($breaker->recordFailure($key))->toBeFalse()
        ->and($breaker->recordFailure($key))->toBeTrue()
        ->and($breaker->isOpen($key))->toBeTrue();

    $breaker->recordSuccess($key);
    expect($breaker->isOpen($key))->toBeFalse();
});

it('no-ops with a clear error when no S3 client is wired', function() {
    $result = (new S3Forwarder())->forward('bucket', 'audit', 'evt-1', '{}');

    expect($result->success)->toBeFalse()
        ->and($result->errorMessage)->toContain('aws/aws-sdk-php');
});

it('ships to the injected S3 client under a time-partitioned key', function() {
    $captured = [];
    $client = new class($captured) implements craftpulse\auditkit\siem\S3ClientInterface {
        public function __construct(private array &$captured)
        {
        }

        public function putObject(string $bucket, string $key, string $body, string $contentType, array $extraArgs = []): void
        {
            $this->captured[] = compact('bucket', 'key', 'contentType');
        }
    };

    $result = (new S3Forwarder($client))->forward('my-bucket', 'audit', 'evt-abc', '{"a":1}');

    expect($result->success)->toBeTrue()
        ->and($captured[0]['bucket'])->toBe('my-bucket')
        ->and($captured[0]['key'])->toMatch('#^audit/\d{4}/\d{2}/\d{2}/evt-abc\.json$#')
        ->and($captured[0]['contentType'])->toBe('application/json');
});
