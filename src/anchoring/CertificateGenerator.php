<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\anchoring;

use Carbon\Carbon;
use craft\helpers\Html;
use craftpulse\auditkit\engine\Canonicalizer;
use craftpulse\auditkit\export\PdfRenderer;

/**
 * CertificateGenerator produces a signed Certificate of Integrity for a chain
 * window: the chain range (start/end id + row hashes), the batch Merkle root,
 * the external anchor receipts, and an HMAC signature over the canonical
 * certificate body. Delivered as JSON (machine-verifiable, and the input to the
 * bundled standalone verifier) and as PDF (human-readable evidence, via
 * {@see PdfRenderer}).
 *
 * The signature binds the certificate contents to the operator's signing key so
 * a tampered certificate is detectable; the anchor receipts bind the Merkle root
 * to systems outside the operator's control so a re-signed certificate over a
 * rewritten chain still fails against the external anchors.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class CertificateGenerator
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The certificate format version, bumped on any body-shape
     * change so a verifier can refuse an unknown shape.
     *
     * @since 1.0.0
     */
    public const VERSION = '1';

    // Public Methods
    // =========================================================================

    /**
     * Builds a signed certificate array from a chain window and its anchors.
     *
     * @param int $startId the first chain id in the window
     * @param string $startRowHash the first row's hash
     * @param int $endId the last chain id in the window
     * @param string $endRowHash the last row's hash
     * @param string $merkleRoot the batch Merkle root
     * @param AnchorReceipt[] $receipts the external anchor receipts
     * @param string $signingKey the HMAC signing key
     * @param string|null $subject an operator-facing label (site/plugin name)
     * @return array<string, mixed> the signed certificate
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function generate(
        int $startId,
        string $startRowHash,
        int $endId,
        string $endRowHash,
        string $merkleRoot,
        array $receipts,
        string $signingKey,
        ?string $subject = null,
    ): array {
        $body = [
            'version' => self::VERSION,
            'subject' => $subject,
            'generatedAt' => Carbon::now('UTC')->toIso8601ZuluString(),
            'algorithm' => 'sha256',
            'chain' => [
                'startId' => $startId,
                'startRowHash' => $startRowHash,
                'endId' => $endId,
                'endRowHash' => $endRowHash,
            ],
            'merkleRoot' => $merkleRoot,
            'anchors' => array_map(static fn(AnchorReceipt $r): array => $r->toArray(), $receipts),
        ];

        $body['signature'] = self::sign($body, $signingKey);

        return $body;
    }

    /**
     * Renders a certificate array as PDF bytes.
     *
     * @param array<string, mixed> $certificate
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function toPdf(array $certificate): string
    {
        return (new PdfRenderer())->render($this->_html($certificate), 'A4', 'portrait');
    }

    // Static Methods
    // =========================================================================

    /**
     * Computes the HMAC signature over the canonical certificate body (every
     * field except the signature itself).
     *
     * @param array<string, mixed> $body the certificate body
     * @param string $signingKey the HMAC key
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function sign(array $body, string $signingKey): string
    {
        unset($body['signature']);

        return hash_hmac('sha256', Canonicalizer::canonicalize($body), $signingKey);
    }

    /**
     * Verifies a certificate's signature against the signing key. Constant-time.
     *
     * @param array<string, mixed> $certificate
     * @param string $signingKey
     * @return bool
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function verifySignature(array $certificate, string $signingKey): bool
    {
        $presented = (string)($certificate['signature'] ?? '');

        return $presented !== '' && hash_equals(self::sign($certificate, $signingKey), $presented);
    }

    // Private Methods
    // =========================================================================

    /**
     * Renders the certificate as an HTML document for the PDF.
     *
     * @param array<string, mixed> $certificate
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private function _html(array $certificate): string
    {
        $chain = $certificate['chain'] ?? [];
        $rows = [
            'Subject' => (string)($certificate['subject'] ?? '-'),
            'Generated' => (string)($certificate['generatedAt'] ?? ''),
            'Algorithm' => (string)($certificate['algorithm'] ?? ''),
            'Chain range' => sprintf('#%s to #%s', $chain['startId'] ?? '?', $chain['endId'] ?? '?'),
            'Start row hash' => (string)($chain['startRowHash'] ?? ''),
            'End row hash' => (string)($chain['endRowHash'] ?? ''),
            'Merkle root' => (string)($certificate['merkleRoot'] ?? ''),
            'Signature' => (string)($certificate['signature'] ?? ''),
        ];

        $body = '';
        foreach ($rows as $label => $value) {
            $body .= '<tr><th>' . Html::encode($label) . '</th><td><code>' . Html::encode($value) . '</code></td></tr>';
        }

        $anchors = '';
        foreach ($certificate['anchors'] ?? [] as $anchor) {
            $anchors .= '<li>' . Html::encode((string)($anchor['provider'] ?? ''))
                . ': ' . Html::encode((string)($anchor['reference'] ?? ''))
                . '</li>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Certificate of Integrity</title>'
            . '<style>body{font-family:sans-serif;font-size:12px}h1{font-size:18px}'
            . 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:6px;text-align:left}'
            . 'th{background:#f4f4f4;width:150px}code{word-break:break-all}</style></head><body>'
            . '<h1>Certificate of Integrity</h1>'
            . '<table>' . $body . '</table>'
            . '<h2>External anchors</h2><ul>' . ($anchors ?: '<li>None</li>') . '</ul>'
            . '<p>Verify this certificate with the bundled standalone verifier: '
            . '<code>php verify-audit-chain.php export.jsonl certificate.json</code></p>'
            . '</body></html>';
    }
}
