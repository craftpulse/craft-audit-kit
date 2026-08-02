<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Tests for the export formatters + streaming exporter: CSV formula-injection
 * neutralisation, the chain-verifiable JSONL envelope, the streamed JSON array,
 * HTML escaping, and the dompdf-backed PDF render.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\auditkit\engine\Canonicalizer;
use craftpulse\auditkit\export\CsvFormatter;
use craftpulse\auditkit\export\HtmlFormatter;
use craftpulse\auditkit\export\JsonFormatter;
use craftpulse\auditkit\export\JsonlFormatter;
use craftpulse\auditkit\export\PdfRenderer;
use craftpulse\auditkit\export\StreamingExporter;

it('emits a CSV header and neutralises formula-injection cells', function() {
    $rows = [
        ['id' => 1, 'event' => '=cmd|calc', 'note' => 'safe'],
        ['id' => 2, 'event' => '+1234', 'note' => '@evil'],
    ];

    $out = (new StreamingExporter())->toString($rows, new CsvFormatter(['id', 'event', 'note']));
    $lines = explode("\n", trim($out));

    expect($lines[0])->toBe('id,event,note')
        // Leading formula triggers are quoted with a leading single-quote, then
        // fputcsv wraps the now-leading-quote value.
        ->and($lines[1])->toContain("'=cmd|calc")
        ->and($lines[2])->toContain("'+1234")
        ->and($lines[2])->toContain("'@evil");
});

it('emits a chain-verifiable JSONL envelope whose payload rehashes to rowHash', function() {
    // Build a real chain row so the envelope's payload rehashes to rowHash.
    $payload = ['event' => 'a', 'dateCreated' => '2026-01-01T00:00:00Z'];
    $previousHash = Canonicalizer::GENESIS_PREVIOUS_HASH;
    $rowHash = hash('sha256', Canonicalizer::canonicalize($payload) . $previousHash);

    $row = $payload + ['id' => 1, 'rowHash' => $rowHash, 'previousHash' => $previousHash];

    $out = (new StreamingExporter())->toString([$row], new JsonlFormatter());
    $decoded = json_decode(trim($out), true);

    expect($decoded['id'])->toBe(1)
        ->and($decoded['rowHash'])->toBe($rowHash)
        ->and($decoded['previousHash'])->toBe($previousHash);

    // Recompute sha256(canonical(payload) . previousHash) straight from the
    // exported envelope — it must equal the exported rowHash.
    $recomputed = hash('sha256', Canonicalizer::canonicalize($decoded['payload']) . $decoded['previousHash']);
    expect($recomputed)->toBe($decoded['rowHash']);
});

it('streams a single JSON array', function() {
    $rows = [['id' => 1], ['id' => 2]];

    $out = (new StreamingExporter())->toString($rows, new JsonFormatter());

    expect($out)->toBe('[{"id":1},{"id":2}]')
        ->and(json_decode($out, true))->toBe([['id' => 1], ['id' => 2]]);
});

it('HTML-escapes cell values to prevent markup injection', function() {
    $rows = [['id' => 1, 'event' => '<script>alert(1)</script>']];

    $out = (new StreamingExporter())->toString($rows, new HtmlFormatter(['id', 'event']));

    expect($out)->toContain('&lt;script&gt;')
        ->and($out)->not->toContain('<script>alert(1)</script>');
});

it('renders HTML to PDF bytes', function() {
    $html = (new StreamingExporter())->toString(
        [['id' => 1, 'event' => 'login']],
        new HtmlFormatter(['id', 'event'], 'Audit'),
    );

    $pdf = (new PdfRenderer())->render($html);

    // PDF files begin with the %PDF- magic bytes.
    expect(substr($pdf, 0, 5))->toBe('%PDF-');
});
