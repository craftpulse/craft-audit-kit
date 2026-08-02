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
 * StreamingExporter is the thin driver that runs a {@see StreamingFormatterInterface}
 * over a row iterable: it emits the formatter's opening bytes, one formatted row
 * per iteration, then the closing bytes. The consumer supplies a `$write`
 * callback that appends bytes wherever it wants (a file handle, an HTTP
 * response, a temp stream) — the exporter never holds more than one row in
 * memory, so a hundred-thousand-row export stays O(1).
 *
 * The consumer owns the row source (a date-bounded query, a batched cursor) and
 * the destination; the kit owns the format bytes.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class StreamingExporter
{
    // Public Methods
    // =========================================================================

    /**
     * Streams the rows through the formatter, invoking `$write` for each chunk
     * of output bytes, and returns the number of rows written.
     *
     * @param iterable<array<string, mixed>> $rows the audit rows, id-ascending
     * @param StreamingFormatterInterface $formatter the format to emit
     * @param callable(string): void $write the byte sink
     * @return int the number of rows written
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function stream(iterable $rows, StreamingFormatterInterface $formatter, callable $write): int
    {
        $write($formatter->open());

        $count = 0;
        foreach ($rows as $row) {
            $write($formatter->row($row, $count === 0));
            $count++;
        }

        $write($formatter->close());

        return $count;
    }

    /**
     * Streams the rows through the formatter and returns the whole output as a
     * string. Convenience for small exports and document formats (HTML feeding
     * the PDF renderer); prefer [[stream()]] with a file/response sink for large
     * exports.
     *
     * @param iterable<array<string, mixed>> $rows the audit rows, id-ascending
     * @param StreamingFormatterInterface $formatter the format to emit
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function toString(iterable $rows, StreamingFormatterInterface $formatter): string
    {
        $buffer = '';
        $this->stream($rows, $formatter, function(string $chunk) use (&$buffer): void {
            $buffer .= $chunk;
        });

        return $buffer;
    }
}
