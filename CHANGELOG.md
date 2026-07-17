# Release Notes for Audit Kit

## 1.0.0-beta.1 - 2026-07-17

> [!NOTE]
> First public beta of the CraftPulse compliance base. The `AuditEvent` contract shape is intended to freeze at 1.0.0 — shape changes after that are a major bump; new outcome/category vocabulary is an additive minor. During the beta, breaking contract changes may still occur between beta releases.

### Added
- `AuditEvent` — frozen-shape, neutral audit event value object (name, category, outcome, emitter, actor, target, scalar-only details, diff, site), with PII-safety enforced at construction.
- `AuditEventType` definitions and the `EventTypes` registry component with fail-closed detail allowlists and `EVENT_REGISTER_AUDIT_EVENTS`.
- `Bus` dispatch component with `AuditSinkInterface`, `EVENT_REGISTER_AUDIT_SINKS`, per-sink isolation, and zero-sink no-op behaviour.
- Tamper-evident chain engine: byte-deterministic `Canonicalizer`, serialized `ChainWriter` (SELECT … FOR UPDATE), `ChainVerifier` with retention-margin and rotation tolerance, and id-prefix-safe `Pruner` with `ChainRotatedEvent`.
- `ContextCapturer` — HMAC-hashed IP and actor identifiers with key rotation, and an injectable GeoIP provider seam (`NullGeoProvider` default).
- Streaming export: CSV (formula-injection guarded), chain-verifiable JSONL, JSON, HTML, and PDF formatters.
- SIEM delivery primitives: RFC 5424 syslog-over-TLS framer, signed webhook signer with secret rotation, HTTP-JSON forwarder (Splunk HEC / Datadog compatible), S3 forwarder seam, and a shared circuit breaker.
- Anchoring module: Merkle batching, RFC 3161 TSA and S3 Object Lock anchor providers, signed Certificate of Integrity (JSON/PDF), and a standalone no-Craft chain verifier (`bin/verify-audit-chain.php`).
- `AuthKitBridge` — forwards Auth Kit `AuthEvent`s (Auth Kit ≥ 1.5.0, suggested dependency) onto the audit bus.
