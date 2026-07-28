# Release Notes for Audit Kit

## Unreleased

### Fixed
- `ChainWriter::write()` now retries a bounded number of times, with full-jitter exponential backoff, when the tail-read or the persist closure's insert hits a MySQL serialization failure (deadlock 1213/`SQLSTATE 40001`, or a lock-wait timeout 1205). Each retry re-reads the chain tail and recomputes `previousHash`/`rowHash` inside a fresh transaction, so nothing is reused across attempts. Once the retry budget is exhausted, `write()` throws the new `craftpulse\auditkit\errors\ChainWriteRetriesExhaustedException` instead of letting the underlying database exception propagate uncaught, so callers can requeue the write instead of silently losing the audit event. Live 12-way and 20-way concurrent-write reproductions found 75% and 90% of writers crashing uncaught on the very first deadlock hit before this fix; the same reproductions after the fix land materially more writes and convert every remaining loss into the new, catchable exception.

## 1.0.0 - 2026-07-18

> [!IMPORTANT]
> First stable release. **The `AuditEvent` contract shape is now frozen**: any change to the value object's shape is a major version bump; new outcome/category vocabulary and new engine capabilities are additive minors; recorders must ignore unknown event names. The chain-engine byte contract (canonicalization, genesis sentinel, rowHash computation) is likewise frozen — it underpins live production chains.

### Added
- Stable contract freeze — no functional changes since 1.0.0-beta.2, which shipped the bridge vocabulary enforcement. The engine is proven in production shape by Password Policy's bit-identity migration (golden-vector verified) and consumed by Ledger, Reeve, and seven emitting plugins.

## 1.0.0-beta.2 - 2026-07-18

### Fixed
- `AuthKitBridge` now enforces Auth Kit's frozen vocabulary: only events whose name is a declared public name constant on the installed `AuthEvent` class are relayed onto the bus (reflected at runtime, so additive-minor vocabulary growth in Auth Kit flows through automatically). Unknown names are dropped with a warning instead of being stamped into recorders' append-only chains under `category: auth`.

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
