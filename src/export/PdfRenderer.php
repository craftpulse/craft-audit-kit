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

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PdfRenderer converts an HTML document (typically {@see HtmlFormatter} output,
 * or a Certificate of Integrity's HTML) into PDF bytes via dompdf — the
 * Craft-ecosystem standard PDF engine. PDF is a document, not a stream, so it
 * sits outside {@see StreamingFormatterInterface}: the consumer renders the full
 * HTML first (small for a certificate, bounded for a report), then hands it here.
 *
 * Remote resources are disabled by default: an audit report is built from
 * first-party HTML and must never fetch attacker-influenced URLs during
 * rendering.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class PdfRenderer
{
    // Public Methods
    // =========================================================================

    /**
     * Renders an HTML document to PDF bytes.
     *
     * @param string $html the HTML document
     * @param string $paper the paper size (e.g. `A4`, `letter`)
     * @param string $orientation `portrait` or `landscape`
     * @return string the PDF bytes
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function render(string $html, string $paper = 'A4', string $orientation = 'landscape'): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        return (string)$dompdf->output();
    }
}
