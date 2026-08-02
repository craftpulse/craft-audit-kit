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

use craft\helpers\Json;
use RuntimeException;

/**
 * CsvFormatter emits an audit export as spreadsheet-friendly CSV with `fputcsv`
 * semantics (comma separator, double-quote enclosure). The column set is fixed
 * at construction so the file's column order is stable auditor evidence.
 *
 * Injection-safe
 * --------------
 * Beyond `fputcsv`'s quoting (which handles commas, quotes, and newlines), this
 * formatter neutralises CSV formula injection: a cell whose value begins with
 * `=`, `+`, `-`, `@`, tab, or carriage-return is prefixed with a single quote so
 * a spreadsheet opening the export treats it as text, never as a formula. Audit
 * rows are attacker-influenced (a crafted username or detail value can land in a
 * cell), so this guard is mandatory, not optional.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class CsvFormatter implements StreamingFormatterInterface
{
    // Public Properties
    // =========================================================================

    /**
     * @var string[] The ordered column keys read from each row.
     *
     * @since 1.0.0
     */
    public readonly array $columns;

    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param string[] $columns the ordered column keys to emit
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function __construct(array $columns)
    {
        $this->columns = $columns;
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
        return 'csv';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function mimeType(): string
    {
        return 'text/csv';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function open(): string
    {
        return self::encodeRow($this->columns) . "\n";
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function row(array $row, bool $isFirst): string
    {
        $values = [];

        foreach ($this->columns as $column) {
            $values[] = self::_sanitizeCell(self::_stringify($row[$column] ?? null));
        }

        return self::encodeRow($values) . "\n";
    }

    // Static Methods
    // =========================================================================

    /**
     * Encodes one row of string values as a CSV line via `fputcsv` semantics,
     * with the trailing newline trimmed (the row writer adds its own).
     *
     * @param array<int, string> $values
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function encodeRow(array $values): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('Failed to open an in-memory stream for CSV encoding.');
        }

        fputcsv($stream, $values, ',', '"', '\\');
        rewind($stream);
        $line = (string)stream_get_contents($stream);
        fclose($stream);

        return rtrim($line, "\r\n");
    }

    // Private Methods
    // =========================================================================

    /**
     * Neutralises CSV formula injection by prefixing a leading formula trigger
     * with a single quote.
     *
     * @param string $value
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private static function _sanitizeCell(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Stringifies a cell value; arrays are JSON-encoded, null becomes an empty
     * string.
     *
     * @param mixed $value
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private static function _stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            return Json::encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string)$value;
    }
}
