# Audit Kit

Foundational, tamper-evident audit primitives for Craft CMS 5: the shared
compliance base for the CraftPulse ecosystem, the way Auth Kit is the shared
identity base.

Audit Kit ships **no tables, no migrations, no control-panel UI, no settings,
and no editions**. It is contract + engine + one bus. Consuming plugins
(Ledger, Password Policy, Reeve, Loadout) `require` it and parameterise the
engine with their own tables, payloads, and storage.

## What it provides

### Contract

- **`audit\AuditEvent`**: a frozen, immutable value object describing one
  auditable fact: `name`, `category`, `emitter`, `outcome`
  (success/failure/warning), `actorId`, `targetType`/`targetId`/`targetUid`,
  scalar-only-no-PII `details`, `diff`, and `eventSiteId`. Enforced at
  construction; the shape is frozen at 1.0.0.
- **`audit\AuditEventType`** + **`services\EventTypes`**: a runtime event-type
  registry with a fail-closed allowlist (`EVENT_REGISTER_AUDIT_EVENTS`). An
  unregistered event type is dropped; a registered type's allowlist strips
  disallowed `details` keys.

### Bus

- **`audit\AuditSinkInterface`** + **`services\Bus`**: the neutral dispatch
  seam (`EVENT_REGISTER_AUDIT_SINKS`). `record(AuditEvent)` fans out to every
  registered sink inside its own try/catch; zero sinks is a cheap no-op.

### Engine (`engine\`)

- **`Canonicalizer`**: the byte-deterministic JSON encoder driving every hash
  chain. Bit-identical to Password Policy's live encoder (recursive
  `SORT_STRING` key order, `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`,
  null/bool/int preserved, list order kept).
- **`ChainWriter`**: the serialized in-transaction chain write (`FOR UPDATE`
  tail read, `rowHash = sha256(canonical . previousHash)`, consumer-supplied
  persist closure).
- **`ChainVerifier`**: walk + first-divergence stop + retention-boundary
  tolerance, with an exit-code contract.
- **`ContextCapturer`**: HMAC'd IP / user-agent / actor identifiers with a
  per-consumer PII-key env var and `securityKey` fallback; optional injected
  geo provider.
- **`Pruner`**: id-prefix-safe retention prune with a `ChainRotatedEvent`.

### Export (`export\`)

Streaming formatters for CSV (formula-injection safe), JSONL (chain-verifiable
envelope), JSON, and HTML, plus a dompdf-backed `PdfRenderer` and a
`StreamingExporter` driver.

### SIEM (`siem\`)

`SyslogFramer` (RFC 5424 over `tls://`), `WebhookSigner` (HMAC with secret
rotation + grace), `HttpJsonForwarder` (https-only, no-redirect; covers Splunk
HEC / Datadog / generic), `S3Forwarder` (behind an SDK-free seam), and a
cache-backed `CircuitBreaker`.

### Anchoring (`anchoring\`)

`MerkleTree` batching, `Rfc3161TsaAnchorProvider` (FreeTSA-style RFC 3161 TSA
client), `S3ObjectLockAnchorProvider` (COMPLIANCE-mode Object Lock), and a
signed `CertificateGenerator` (JSON + PDF). A dependency-free standalone
verifier ships at `bin/verify-audit-chain.php`:

```bash
php verify-audit-chain.php export.jsonl [certificate.json]
```

## Auth Kit bridge

With Auth Kit (^1.5.0) present, `integrations\AuthKitBridge` relays every
authentication event onto the audit bus (`Audit::EVENT_AFTER_RECORD` →
`AuditEvent`, category `auth`), so recorders wire a single seam. Auth Kit is
`suggest`ed, never `require`d, and the bridge is a clean no-op when it is absent.

## Requirements

- Craft CMS 5.0.0+
- PHP 8.2+
- `dompdf/dompdf` (PDF export + certificates)
- `aws/aws-sdk-php` is `suggest`ed for the S3 forwarder and S3 Object Lock
  anchor provider.

## License

MIT
