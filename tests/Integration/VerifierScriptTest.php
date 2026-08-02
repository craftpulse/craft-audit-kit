<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * End-to-end coverage for the bundled standalone verifier (`bin/verify-audit-chain.php`).
 * It is exercised exactly as an auditor would: a real JSONL export + certificate
 * are written to disk, then the script is invoked as a separate PHP process with
 * NO Craft bootstrap. A clean chain exits 0, a tampered row exits 1, and a
 * certificate whose Merkle root matches the export passes — proving the script's
 * from-scratch canonicalisation reproduces the writer's bytes.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\auditkit\anchoring\CertificateGenerator;
use craftpulse\auditkit\anchoring\MerkleTree;
use craftpulse\auditkit\engine\Canonicalizer;
use craftpulse\auditkit\export\JsonlFormatter;
use craftpulse\auditkit\export\StreamingExporter;

/**
 * Builds real chain rows (payload + linked rowHash/previousHash), returning the
 * export rows and the ordered rowHashes.
 *
 * @return array{0: array<int, array<string, mixed>>, 1: string[]}
 */
function buildExportRows(): array
{
    $payloads = [
        ['event' => 'a', 'dateCreated' => '2026-01-01T00:00:00Z'],
        ['event' => 'b', 'dateCreated' => '2026-01-02T00:00:00Z'],
        ['event' => 'c', 'dateCreated' => '2026-01-03T00:00:00Z'],
    ];

    $rows = [];
    $rowHashes = [];
    $previousHash = Canonicalizer::GENESIS_PREVIOUS_HASH;
    $id = 1;

    foreach ($payloads as $payload) {
        $rowHash = hash('sha256', Canonicalizer::canonicalize($payload) . $previousHash);
        $rows[] = $payload + ['id' => $id++, 'rowHash' => $rowHash, 'previousHash' => $previousHash];
        $rowHashes[] = $rowHash;
        $previousHash = $rowHash;
    }

    return [$rows, $rowHashes];
}

it('verifies a clean export and matching certificate with no Craft bootstrap', function() {
    [$rows, $rowHashes] = buildExportRows();

    $jsonl = (new StreamingExporter())->toString($rows, new JsonlFormatter());
    $exportPath = tempnam(sys_get_temp_dir(), 'ak_export_') . '.jsonl';
    file_put_contents($exportPath, $jsonl);

    $root = (new MerkleTree($rowHashes))->root();
    $cert = (new CertificateGenerator())->generate(
        startId: 1,
        startRowHash: $rowHashes[0],
        endId: 3,
        endRowHash: $rowHashes[2],
        merkleRoot: $root,
        receipts: [],
        signingKey: 'k',
    );
    $certPath = tempnam(sys_get_temp_dir(), 'ak_cert_') . '.json';
    file_put_contents($certPath, json_encode($cert));

    $script = dirname(__DIR__, 2) . '/bin/verify-audit-chain.php';
    $output = [];
    $exitCode = 0;
    exec(sprintf('php %s %s %s 2>&1', escapeshellarg($script), escapeshellarg($exportPath), escapeshellarg($certPath)), $output, $exitCode);

    @unlink($exportPath);
    @unlink($certPath);

    expect($exitCode)->toBe(0)
        ->and(implode("\n", $output))->toContain('3 rows verified')
        ->and(implode("\n", $output))->toContain('Merkle root matches');
});

it('exits non-zero on a tampered export row', function() {
    [$rows] = buildExportRows();

    // Tamper the second row's payload without recomputing its rowHash.
    $rows[1]['event'] = 'TAMPERED';

    $jsonl = (new StreamingExporter())->toString($rows, new JsonlFormatter());
    $exportPath = tempnam(sys_get_temp_dir(), 'ak_export_') . '.jsonl';
    file_put_contents($exportPath, $jsonl);

    $script = dirname(__DIR__, 2) . '/bin/verify-audit-chain.php';
    $output = [];
    $exitCode = 0;
    exec(sprintf('php %s %s 2>&1', escapeshellarg($script), escapeshellarg($exportPath)), $output, $exitCode);

    @unlink($exportPath);

    expect($exitCode)->toBe(1)
        ->and(implode("\n", $output))->toContain('rowHash mismatch');
});
