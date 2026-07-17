<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\export;

use craft\helpers\Html;
use craft\helpers\Json;

/**
 * HtmlFormatter emits an audit export as a self-contained HTML document with a
 * single table — the human-readable Trails-parity format, and the source
 * {@see PdfRenderer} converts into a PDF. The column set is fixed at
 * construction for stable output. Every cell value is HTML-escaped through
 * {@see Html::encode()}, so an attacker-influenced audit value can never inject
 * markup into the report.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class HtmlFormatter implements StreamingFormatterInterface
{
    // Public Properties
    // =========================================================================

    /**
     * @var string[] The ordered column keys read from each row.
     *
     * @since 1.0.0
     */
    public readonly array $columns;

    /**
     * @var string The document title.
     *
     * @since 1.0.0
     */
    public readonly string $title;

    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param string[] $columns the ordered column keys to emit
     * @param string $title the document title
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function __construct(array $columns, string $title = 'Audit Export')
    {
        $this->columns = $columns;
        $this->title = $title;
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function close(): string
    {
        return "</tbody></table></body></html>";
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function extension(): string
    {
        return 'html';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function mimeType(): string
    {
        return 'text/html';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function open(): string
    {
        $head = '<thead><tr>';
        foreach ($this->columns as $column) {
            $head .= '<th>' . Html::encode($column) . '</th>';
        }
        $head .= '</tr></thead><tbody>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
            . Html::encode($this->title)
            . '</title><style>body{font-family:sans-serif;font-size:11px}'
            . 'table{border-collapse:collapse;width:100%}'
            . 'th,td{border:1px solid #ccc;padding:4px;text-align:left;vertical-align:top}'
            . 'th{background:#f4f4f4}</style></head><body><h1>'
            . Html::encode($this->title)
            . '</h1><table>' . $head;
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function row(array $row, bool $isFirst): string
    {
        $cells = '';

        foreach ($this->columns as $column) {
            $value = $row[$column] ?? null;

            if (is_array($value)) {
                $value = Json::encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $cells .= '<td>' . Html::encode((string)($value ?? '')) . '</td>';
        }

        return '<tr>' . $cells . '</tr>';
    }
}
