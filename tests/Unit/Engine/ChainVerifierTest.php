<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Tests for the chain verifier: a clean genesis-rooted walk, first-divergence
 * detection on both a rowHash tamper and a previousHash break, and the
 * retention-boundary tolerance for a pruned chain head.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\auditkit\engine\Canonicalizer;
use craftpulse\auditkit\engine\ChainVerifier;

/**
 * Builds a linked chain of rows from a list of payloads, computing each row's
 * rowHash/previousHash exactly as the writer would. Returns the rows plus the
 * rebuild closure the verifier needs.
 *
 * @param array<int, array<string, mixed>> $payloads
 * @return array{0: array<int, array<string, mixed>>, 1: callable}
 */
function buildChain(array $payloads, string $genesis = null): array
{
    $genesis ??= Canonicalizer::GENESIS_PREVIOUS_HASH;
    $rows = [];
    $previousHash = $genesis;
    $id = 1;

    foreach ($payloads as $payload) {
        $rowHash = hash('sha256', Canonicalizer::canonicalize($payload) . $previousHash);
        $rows[] = [
            'id' => $id++,
            'dateCreated' => $payload['dateCreated'],
            'previousHash' => $previousHash,
            'rowHash' => $rowHash,
            '_payload' => $payload,
        ];
        $previousHash = $rowHash;
    }

    $rebuild = fn(array $row): array => $row['_payload'];

    return [$rows, $rebuild];
}

it('verifies a clean genesis-rooted chain', function() {
    [$rows, $rebuild] = buildChain([
        ['event' => 'a', 'dateCreated' => '2026-01-01T00:00:00Z'],
        ['event' => 'b', 'dateCreated' => '2026-01-02T00:00:00Z'],
        ['event' => 'c', 'dateCreated' => '2026-01-03T00:00:00Z'],
    ]);

    $result = (new ChainVerifier())->verify($rows, $rebuild);

    expect($result->isValid())->toBeTrue()
        ->and($result->verifiedRows)->toBe(3)
        ->and($result->totalRows)->toBe(3);
});

it('detects a rowHash tamper at the offending row', function() {
    [$rows, $rebuild] = buildChain([
        ['event' => 'a', 'dateCreated' => '2026-01-01T00:00:00Z'],
        ['event' => 'b', 'dateCreated' => '2026-01-02T00:00:00Z'],
    ]);

    // Tamper row 2's stored rowHash.
    $rows[1]['rowHash'] = str_repeat('f', 64);

    $result = (new ChainVerifier())->verify($rows, $rebuild);

    expect($result->isValid())->toBeFalse()
        ->and($result->exitCode)->toBe(ChainVerifier::EXIT_CHAIN_BREAK)
        ->and($result->breakId)->toBe(2)
        ->and($result->breakReason)->toBe('rowHash mismatch')
        ->and($result->verifiedRows)->toBe(1);
});

it('detects a previousHash break', function() {
    [$rows, $rebuild] = buildChain([
        ['event' => 'a', 'dateCreated' => '2026-01-01T00:00:00Z'],
        ['event' => 'b', 'dateCreated' => '2026-01-02T00:00:00Z'],
    ]);

    // Break row 2's link to row 1.
    $rows[1]['previousHash'] = str_repeat('a', 64);

    $result = (new ChainVerifier())->verify($rows, $rebuild);

    expect($result->isValid())->toBeFalse()
        ->and($result->breakId)->toBe(2)
        ->and($result->breakReason)->toBe('previousHash mismatch');
});

it('rejects a non-genesis first row in a full walk within the retention window', function() {
    // First row's previousHash is not the genesis sentinel and the row is
    // recent — no acceptable boundary, so it's a break.
    [$rows, $rebuild] = buildChain(
        [['event' => 'a', 'dateCreated' => gmdate('Y-m-d\TH:i:s\Z')]],
        genesis: str_repeat('b', 64),
    );

    $result = (new ChainVerifier())->verify($rows, $rebuild, isFullWalk: true, retentionDays: 365);

    expect($result->isValid())->toBeFalse()
        ->and($result->breakReason)->toBe('previousHash mismatch');
});

it('accepts a pruned chain head older than the retention window as a boundary', function() {
    // First row's previousHash references a now-deleted row, and the row is
    // older than retentionDays widened by the safety margin — an acceptable
    // retention boundary in full-walk mode.
    [$rows, $rebuild] = buildChain(
        [
            ['event' => 'a', 'dateCreated' => '2020-01-01T00:00:00Z'],
            ['event' => 'b', 'dateCreated' => '2020-01-02T00:00:00Z'],
        ],
        genesis: str_repeat('c', 64),
    );

    $result = (new ChainVerifier())->verify($rows, $rebuild, isFullWalk: true, retentionDays: 30);

    expect($result->isValid())->toBeTrue()
        ->and($result->verifiedRows)->toBe(2);
});

it('trusts the stored previousHash of a bounded start', function() {
    // A bounded walk (isFullWalk false) trusts the first row's stored
    // previousHash as the anchor even when it isn't the genesis sentinel.
    [$rows, $rebuild] = buildChain(
        [['event' => 'a', 'dateCreated' => gmdate('Y-m-d\TH:i:s\Z')]],
        genesis: str_repeat('d', 64),
    );

    $result = (new ChainVerifier())->verify($rows, $rebuild, isFullWalk: false);

    expect($result->isValid())->toBeTrue();
});
