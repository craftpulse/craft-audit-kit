<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Pest coverage for `Canonicalizer::canonicalize()` — the byte-stable JSON
 * encoder that drives every audit hash chain in the estate.
 *
 * These assertions are ported UNCHANGED from Password Policy's
 * `tests/Integration/Services/AuditCanonicalisationTest.php` (only the class
 * under test is repointed). They are the bit-identity gate: live PP 5.1.x
 * installs carry production chains written with PP's own copy of this encoder,
 * so the kit encoder must reproduce PP's exact bytes. If any expectation drifts,
 * the encoding diverged — STOP and report, do not "fix" by changing the kit.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\auditkit\engine\Canonicalizer;

// =============================================================================
// Key order — same input shape encoded identically regardless of source order
// =============================================================================

it('produces identical output for re-ordered input keys', function() {
    $a = Canonicalizer::canonicalize(['z' => 1, 'a' => 2]);
    $b = Canonicalizer::canonicalize(['a' => 2, 'z' => 1]);

    expect($a)->toBe($b);
    expect($a)->toBe('{"a":2,"z":1}');
});

it('sorts nested array keys recursively', function() {
    $a = Canonicalizer::canonicalize([
        'outer' => ['z' => 1, 'a' => 2],
        'b' => 'second',
        'a' => 'first',
    ]);
    $b = Canonicalizer::canonicalize([
        'a' => 'first',
        'b' => 'second',
        'outer' => ['a' => 2, 'z' => 1],
    ]);

    expect($a)->toBe($b);
    expect($a)->toBe('{"a":"first","b":"second","outer":{"a":2,"z":1}}');
});

// =============================================================================
// Null preservation — null values must NOT be stripped
// =============================================================================

it('preserves null values rather than stripping them', function() {
    $output = Canonicalizer::canonicalize(['k' => null, 'present' => 1]);

    expect($output)->toBe('{"k":null,"present":1}');
});

// =============================================================================
// Boolean encoding — true/false not coerced to 1/0
// =============================================================================

it('encodes booleans as true and false (not 1 and 0)', function() {
    $output = Canonicalizer::canonicalize(['enabled' => true, 'disabled' => false]);

    expect($output)->toBe('{"disabled":false,"enabled":true}');
});

// =============================================================================
// Integer passthrough — bare numerics, not quoted strings
// =============================================================================

it('encodes integers as bare numerics', function() {
    $output = Canonicalizer::canonicalize(['count' => 42, 'big' => 9007199254740992]);

    expect($output)->toBe('{"big":9007199254740992,"count":42}');
});

// =============================================================================
// Unicode passthrough — JSON_UNESCAPED_UNICODE keeps the original code points
// =============================================================================

it('emits Unicode code points unescaped', function() {
    $output = Canonicalizer::canonicalize(['name' => 'café résumé']);

    expect($output)->toBe('{"name":"café résumé"}');
});

// =============================================================================
// Forward-slash passthrough — JSON_UNESCAPED_SLASHES keeps slashes literal
// =============================================================================

it('emits forward slashes unescaped', function() {
    $output = Canonicalizer::canonicalize(['url' => 'https://example.test/path']);

    expect($output)->toBe('{"url":"https://example.test/path"}');
});

// =============================================================================
// List-shaped values — integer order preserved even at ≥ 10 entries
// =============================================================================

it('preserves list order for numerically-indexed arrays with ≥ 10 entries', function() {
    $list = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l'];

    $output = Canonicalizer::canonicalize(['items' => $list]);

    expect($output)->toBe('{"items":["a","b","c","d","e","f","g","h","i","j","k","l"]}');
});

// =============================================================================
// Golden-string regression — full audit-shape payload, hard-coded reference
// =============================================================================

it('matches the golden canonical string for a representative payload', function() {
    $payload = [
        'changedByIdentifier' => 'hmac-of-actor-email',
        'dateCreated' => '2026-05-07T08:12:01Z',
        'details' => ['violationType' => 'expired', 'reason' => 'Force reset'],
        'event' => 'force_reset',
        'ipHash' => 'abc123',
        'outcome' => 'success',
        'source' => 'admin',
        'uid' => '5b3f-uid',
        'userIdentifier' => 'hmac-of-email',
    ];

    $expected = '{"changedByIdentifier":"hmac-of-actor-email",'
        . '"dateCreated":"2026-05-07T08:12:01Z",'
        . '"details":{"reason":"Force reset","violationType":"expired"},'
        . '"event":"force_reset",'
        . '"ipHash":"abc123",'
        . '"outcome":"success",'
        . '"source":"admin",'
        . '"uid":"5b3f-uid",'
        . '"userIdentifier":"hmac-of-email"}';

    expect(Canonicalizer::canonicalize($payload))->toBe($expected);
});

// =============================================================================
// Golden-vector rowHash — the PP live chain-write code path, byte-reproduced
// =============================================================================

it('reproduces Password Policy\'s live rowHash for a genesis chain write', function() {
    // A realistic PP-shaped canonical payload — the exact 9-key set PP's
    // AuditLogService::logEvent() hashes (changedByIdentifier, dateCreated,
    // details, event, ipHash, outcome, source, uid, userIdentifier).
    $payload = [
        'changedByIdentifier' => 'hmac-of-actor-email',
        'dateCreated' => '2026-05-07T08:12:01Z',
        'details' => ['reason' => 'Force reset', 'violationType' => 'expired'],
        'event' => 'password_reset_forced',
        'ipHash' => 'abc123',
        'outcome' => 'success',
        'source' => 'admin',
        'uid' => '5b3f-uid',
        'userIdentifier' => 'hmac-of-email',
    ];

    // PP's genesis write: rowHash = sha256(canonicalize(payload) . GENESIS).
    // The expected value is computed independently here with the frozen PP
    // recipe — canonical bytes concatenated with the 64-zero genesis sentinel,
    // sha256 hex. If the kit's ChainWriter/Canonicalizer produced a different
    // byte anywhere, this vector fails.
    $canonical = '{"changedByIdentifier":"hmac-of-actor-email",'
        . '"dateCreated":"2026-05-07T08:12:01Z",'
        . '"details":{"reason":"Force reset","violationType":"expired"},'
        . '"event":"password_reset_forced",'
        . '"ipHash":"abc123",'
        . '"outcome":"success",'
        . '"source":"admin",'
        . '"uid":"5b3f-uid",'
        . '"userIdentifier":"hmac-of-email"}';

    $genesis = '0000000000000000000000000000000000000000000000000000000000000000';
    $expectedRowHash = hash('sha256', $canonical . $genesis);

    // The kit's own encoding + the frozen genesis constant must reproduce it.
    $actualRowHash = hash(
        'sha256',
        Canonicalizer::canonicalize($payload) . Canonicalizer::GENESIS_PREVIOUS_HASH,
    );

    expect(Canonicalizer::canonicalize($payload))->toBe($canonical)
        ->and($actualRowHash)->toBe($expectedRowHash)
        ->and(Canonicalizer::GENESIS_PREVIOUS_HASH)->toBe($genesis);
});
