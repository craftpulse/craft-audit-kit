#!/usr/bin/env php
<?php
/**
 * Audit Kit — standalone audit-chain verifier.
 *
 * A single, dependency-free PHP file that re-walks a JSONL audit export and,
 * optionally, checks it against a Certificate of Integrity. It boots NO
 * framework: an auditor copies this one file next to an export and runs it, and
 * the credibility of the whole tamper-evidence claim rests on that — the
 * verifier is readable, runnable, and paywall-free by design.
 *
 * It reproduces the estate's canonicalisation recipe exactly (recursive
 * SORT_STRING ksort with a list guard, JSON_UNESCAPED_SLASHES |
 * JSON_UNESCAPED_UNICODE, null/bool/int preserved) so the hashes it recomputes
 * match the writer's bytes. Keep this recipe in lockstep with the kit's
 * Canonicalizer; drift here silently invalidates the verification.
 *
 * Usage:
 *   php verify-audit-chain.php <export.jsonl> [certificate.json]
 *
 * Exit codes:
 *   0  chain valid (and, if given, the certificate's Merkle root matches)
 *   1  chain break, or Merkle-root mismatch against the certificate
 *   2  unreadable input / malformed JSON / usage error
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

const GENESIS_PREVIOUS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';
const EXIT_OK = 0;
const EXIT_CHAIN_BREAK = 1;
const EXIT_UNREADABLE = 2;

/**
 * Recursively sorts associative array keys with SORT_STRING; list arrays keep
 * their order. Mirror of the kit Canonicalizer.
 */
function ak_sort_recursive(array $value): array
{
    foreach ($value as $key => $inner) {
        if (is_array($inner)) {
            $value[$key] = ak_sort_recursive($inner);
        }
    }

    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return $value;
}

/**
 * Canonicalises a payload to the byte-stable JSON the chain hashes.
 */
function ak_canonicalize(array $payload): string
{
    return json_encode(ak_sort_recursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Hashes a pair of hex nodes as raw bytes, returning the hex parent.
 */
function ak_hash_pair(string $left, string $right): string
{
    return hash('sha256', hex2bin($left) . hex2bin($right));
}

/**
 * Computes a Merkle root over an ordered list of hex leaf hashes (odd tail
 * duplicates the last node). Mirror of the kit MerkleTree.
 */
function ak_merkle_root(array $leaves): string
{
    $level = array_values($leaves);

    while (count($level) > 1) {
        $next = [];
        for ($i = 0; $i < count($level); $i += 2) {
            $left = $level[$i];
            $right = $level[$i + 1] ?? $left;
            $next[] = ak_hash_pair($left, $right);
        }
        $level = $next;
    }

    return $level[0];
}

$argvLocal = $argv ?? [];

if (count($argvLocal) < 2) {
    fwrite(STDERR, "Usage: php verify-audit-chain.php <export.jsonl> [certificate.json]\n");
    exit(EXIT_UNREADABLE);
}

$exportPath = $argvLocal[1];

if (!is_readable($exportPath)) {
    fwrite(STDERR, "Cannot read export file: {$exportPath}\n");
    exit(EXIT_UNREADABLE);
}

$handle = fopen($exportPath, 'rb');

if ($handle === false) {
    fwrite(STDERR, "Cannot open export file: {$exportPath}\n");
    exit(EXIT_UNREADABLE);
}

$previousHash = null;
$verified = 0;
$rowHashes = [];
$lineNo = 0;

while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    $lineNo++;

    if ($line === '') {
        continue;
    }

    $envelope = json_decode($line, true);

    if (!is_array($envelope) || !isset($envelope['payload'], $envelope['rowHash'], $envelope['previousHash'])) {
        fwrite(STDERR, "Malformed JSONL at line {$lineNo}.\n");
        fclose($handle);
        exit(EXIT_UNREADABLE);
    }

    $expectedHash = hash('sha256', ak_canonicalize($envelope['payload']) . $envelope['previousHash']);

    // First row roots at the genesis sentinel OR a pruned-chain boundary; a
    // standalone verifier trusts the first stored previousHash as its anchor.
    if ($previousHash !== null && $envelope['previousHash'] !== $previousHash) {
        fwrite(STDERR, "BREAK at line {$lineNo} (id " . ($envelope['id'] ?? '?') . "): previousHash mismatch.\n");
        fclose($handle);
        exit(EXIT_CHAIN_BREAK);
    }

    if ($expectedHash !== $envelope['rowHash']) {
        fwrite(STDERR, "BREAK at line {$lineNo} (id " . ($envelope['id'] ?? '?') . "): rowHash mismatch.\n");
        fwrite(STDERR, "  expected: {$expectedHash}\n  stored:   {$envelope['rowHash']}\n");
        fclose($handle);
        exit(EXIT_CHAIN_BREAK);
    }

    $rowHashes[] = $envelope['rowHash'];
    $previousHash = $envelope['rowHash'];
    $verified++;
}

fclose($handle);

echo "OK: {$verified} rows verified.\n";

// Optional certificate check: recompute the Merkle root over the verified row
// hashes and compare it to the certificate's committed root.
if (isset($argvLocal[2])) {
    $certPath = $argvLocal[2];

    if (!is_readable($certPath)) {
        fwrite(STDERR, "Cannot read certificate file: {$certPath}\n");
        exit(EXIT_UNREADABLE);
    }

    $certificate = json_decode((string)file_get_contents($certPath), true);

    if (!is_array($certificate) || !isset($certificate['merkleRoot'])) {
        fwrite(STDERR, "Malformed certificate.\n");
        exit(EXIT_UNREADABLE);
    }

    if ($rowHashes === []) {
        fwrite(STDERR, "No rows to reconstruct a Merkle root from.\n");
        exit(EXIT_UNREADABLE);
    }

    $computedRoot = ak_merkle_root($rowHashes);

    if (!hash_equals((string)$certificate['merkleRoot'], $computedRoot)) {
        fwrite(STDERR, "Merkle-root MISMATCH.\n  certificate: {$certificate['merkleRoot']}\n  computed:    {$computedRoot}\n");
        exit(EXIT_CHAIN_BREAK);
    }

    echo "OK: Merkle root matches the certificate ({$computedRoot}).\n";

    // Signature check is available when the operator supplies the signing key
    // out-of-band (the verifier stays public, so the key is never bundled).
    $key = getenv('AUDIT_CERT_KEY');

    if (is_string($key) && $key !== '') {
        $presented = (string)($certificate['signature'] ?? '');
        $body = $certificate;
        unset($body['signature']);
        $expected = hash_hmac('sha256', ak_canonicalize($body), $key);

        if (!hash_equals($expected, $presented)) {
            fwrite(STDERR, "Certificate signature INVALID.\n");
            exit(EXIT_CHAIN_BREAK);
        }

        echo "OK: certificate signature is valid.\n";
    }
}

exit(EXIT_OK);
