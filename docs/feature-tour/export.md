# Export

Audit Kit ships streaming export formatters so a consuming plugin can hand an auditor a file without loading a whole chain into memory. You own the row source and the destination; the kit owns the byte formatting.

```php
use craftpulse\auditkit\export\CsvFormatter;
use craftpulse\auditkit\export\StreamingExporter;

$formatter = new CsvFormatter(['id', 'dateCreated', 'eventName', 'outcome', 'actorId']);
$exporter = new StreamingExporter();

$rows = $exporter->stream($myRowGenerator, $formatter, function(string $bytes): void {
    echo $bytes;
});
```

## The exporter

`StreamingExporter` is stateless. The formatter is passed per call rather than injected, so one exporter serves every format.

| Method | Description |
|---|---|
| `stream(iterable $rows, StreamingFormatterInterface $formatter, callable $write): int` | Drives the formatter over the rows, pushing every byte to the `$write` sink, and returns the number of rows written. |
| `toString(iterable $rows, StreamingFormatterInterface $formatter): string` | Buffers the same output into a string, for small exports and for document formats that feed the PDF renderer. |

`$rows` is any `iterable` of `array<string, mixed>`, so a generator, a batched query cursor, or a plain array all work. Pass rows in id-ascending order: the JSONL envelope is only chain-verifiable in chain order.

Use `stream()` with a sink that writes straight to the response or to a file handle when the export is large. Use `toString()` when the whole document must exist before you can do anything with it, which is the case for PDF, at the cost of holding it in memory.

## Formats

| Formatter | Description |
|---|---|
| `CsvFormatter` | Emits comma-separated rows with a header line, guarded against spreadsheet formula injection. |
| `JsonlFormatter` | Emits one chain-verifiable JSON envelope per line, so an auditor can recompute the hash chain from the file alone. |
| `JsonFormatter` | Emits a single JSON array of raw rows. |
| `HtmlFormatter` | Emits a complete HTML document with an inline stylesheet and one table row per audit row. |

Every formatter reports its own `extension()` and `mimeType()`, so a download response can be built without a format switch of your own.

### `CsvFormatter`

```php
new CsvFormatter(['id', 'dateCreated', 'eventName', 'outcome', 'actorId']);
```

The constructor takes the ordered column keys. `open()` emits them as the header line, and every row reads exactly those keys, so a row missing one yields an empty cell rather than a ragged line.

Any cell whose first character is `=`, `+`, `-`, `@`, a tab, or a carriage return is prefixed with a single quote before encoding. That is what stops a spreadsheet from executing an audit payload as a formula when the file is opened. Values are stringified first: `null` becomes an empty string, booleans become `true` and `false`, and arrays are JSON-encoded.

`CsvFormatter::encodeRow(array $values): string` is public and static, so you can reuse the same CSV encoding for a summary line or a footer outside the exporter.

### `JsonlFormatter`

```php
new JsonlFormatter(['id', 'rowHash', 'previousHash']);
```

The constructor takes the row keys that form the envelope rather than the canonical payload, and defaults to `id`, `rowHash`, and `previousHash`. Every other key in the row is treated as payload.

Each line is one JSON object with exactly four keys:

```json
{"id": 1, "payload": {}, "rowHash": "", "previousHash": ""}
```

`payload` is the row with the envelope keys removed, passed through the same `Canonicalizer` the chain writer uses. That is what makes the file self-verifying: an auditor recomputes `sha256(payload . previousHash)` for each line and compares it to `rowHash`, with no access to your database and no Craft install. This is the format the standalone verifier at `bin/verify-audit-chain.php` reads.

Pass the same chain column names you used when writing the chain. If your table calls them something else, the payload will silently include your hash columns and every recomputation will fail.

### `HtmlFormatter`

```php
new HtmlFormatter(['id', 'dateCreated', 'eventName'], 'Audit Export');
```

The second argument is the document title and defaults to `Audit Export`. Every header and cell is passed through `craft\helpers\Html::encode()`, so audit payloads containing markup are rendered as text.

## PDF

`PdfRenderer` sits deliberately outside the streaming interface. A PDF cannot be streamed row by row, so you render HTML first and then convert it.

```php
use craftpulse\auditkit\export\HtmlFormatter;
use craftpulse\auditkit\export\PdfRenderer;
use craftpulse\auditkit\export\StreamingExporter;

$html = (new StreamingExporter())->toString($rows, new HtmlFormatter($columns, 'Audit Export'));
$pdf = (new PdfRenderer())->render($html);
```

`render(string $html, string $paper = 'A4', string $orientation = 'landscape'): string` returns the PDF bytes. Remote resource loading is disabled in the underlying dompdf options, so a payload containing an external image URL cannot make the renderer fetch it.

Landscape is the default because audit tables are wide. Pass `portrait` when you are rendering a narrow summary rather than a row dump.

## Writing your own formatter

If none of the shipped formats fit, you can write your own by implementing the `StreamingFormatterInterface` interface. See the implementation of the `craftpulse\auditkit\export\CsvFormatter` class.

```php
use craftpulse\auditkit\export\StreamingFormatterInterface;

class MyFormatter implements StreamingFormatterInterface
{
    // Implement the interface methods
}
```

| Method | Description |
|---|---|
| `open(): string` | Returns the bytes emitted once before the first row, or an empty string for line formats. |
| `row(array $row, bool $isFirst): string` | Returns the formatted bytes for one audit row, including any trailing newline. |
| `close(): string` | Returns the bytes emitted once after the last row, or an empty string for line formats. |
| `extension(): string` | Returns the file extension for this format, without the dot. |
| `mimeType(): string` | Returns the MIME type for this format. |

The exporter calls `open()` once, then `row()` for every audit row in id-ascending order, then `close()` once. `$isFirst` exists for formats that separate rows, such as the comma between JSON array elements; ignore it in line formats.

The formatters are plain classes with no registration event and no base class to extend, so you can keep a formatter entirely in your own plugin and update it independently of Audit Kit.
