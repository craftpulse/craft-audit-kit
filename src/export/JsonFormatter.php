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

/**
 * JsonFormatter emits an audit export as a single JSON array. It streams the
 * array element-by-element (`[` on open, comma-separated rows, `]` on close) so
 * memory stays bounded even though the output is one document. Each element is
 * the row as-is, encoded with the estate's canonical JSON flags.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class JsonFormatter implements StreamingFormatterInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function close(): string
    {
        return ']';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function extension(): string
    {
        return 'json';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function mimeType(): string
    {
        return 'application/json';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function open(): string
    {
        return '[';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function row(array $row, bool $isFirst): string
    {
        $prefix = $isFirst ? '' : ',';

        return $prefix . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
