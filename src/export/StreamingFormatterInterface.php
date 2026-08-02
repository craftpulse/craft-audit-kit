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
 * StreamingFormatterInterface is the row-at-a-time export contract. A consumer's
 * export job or controller drives it: [[open()]] once, [[row()]] per audit row
 * in id-ascending order, then [[close()]] once. Keeping formatting per-row means
 * the export's memory footprint stays O(1) regardless of table size — a
 * production audit table can exceed hundreds of thousands of rows.
 *
 * The kit ships CSV, JSONL, JSON, and HTML implementations. PDF is a document
 * (not a stream) format: {@see PdfRenderer} converts the accumulated HTML into a
 * PDF once the stream closes.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
interface StreamingFormatterInterface
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the closing bytes emitted once after the last row (a JSON `]`, an
     * HTML `</table>` footer, or an empty string for line formats).
     *
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function close(): string;

    /**
     * Returns the file extension for this format (without the dot).
     *
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function extension(): string;

    /**
     * Returns the MIME type for this format.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function mimeType(): string;

    /**
     * Returns the opening bytes emitted once before the first row (a CSV header,
     * a JSON `[`, an HTML `<table>` preamble, or an empty string).
     *
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function open(): string;

    /**
     * Returns the formatted bytes for one row, including any trailing newline.
     *
     * @param array<string, mixed> $row the audit row
     * @param bool $isFirst whether this is the first row in the stream (for
     * formats that separate rows, such as the JSON array)
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function row(array $row, bool $isFirst): string;
}
