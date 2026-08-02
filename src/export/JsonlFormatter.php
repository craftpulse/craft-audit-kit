<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\export;

use craftpulse\auditkit\engine\Canonicalizer;

/**
 * JsonlFormatter emits an audit export as newline-delimited JSON, one row per
 * line. Each line is a chain-verifiable envelope:
 * `{"id": ..., "payload": <canonical>, "rowHash": ..., "previousHash": ...}`,
 * where `payload` is the {@see Canonicalizer} output for every row column EXCEPT
 * the chain columns. Reusing the chain-canonical bytes means an auditor can
 * recompute `sha256(payload . previousHash)` straight from the export file and
 * match it against `rowHash` — the export is self-verifying, no source-row
 * re-derivation needed.
 *
 * The chain columns (`id`, `rowHash`, `previousHash` by default) are lifted into
 * the envelope and excluded from the canonical payload, so the payload bytes
 * match exactly what the writer hashed.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class JsonlFormatter implements StreamingFormatterInterface
{
    // Public Properties
    // =========================================================================

    /**
     * @var string[] The row keys that form the envelope rather than the
     * canonical payload.
     *
     * @since 1.0.0
     */
    public readonly array $chainColumns;

    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param string[] $chainColumns the row keys lifted into the envelope and
     * excluded from the canonical payload
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function __construct(array $chainColumns = ['id', 'rowHash', 'previousHash'])
    {
        $this->chainColumns = $chainColumns;
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function close(): string
    {
        return '';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function extension(): string
    {
        return 'jsonl';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function mimeType(): string
    {
        return 'application/x-ndjson';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function open(): string
    {
        return '';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function row(array $row, bool $isFirst): string
    {
        $payload = $row;
        foreach ($this->chainColumns as $column) {
            unset($payload[$column]);
        }

        $envelope = [
            'id' => isset($row['id']) ? (int)$row['id'] : null,
            'payload' => json_decode(Canonicalizer::canonicalize($payload), true),
            'rowHash' => $row['rowHash'] ?? '',
            'previousHash' => $row['previousHash'] ?? '',
        ];

        return json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
