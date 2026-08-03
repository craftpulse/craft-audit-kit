# Release Notes for Audit Kit

## 1.1.1 - 2026-08-03

- Fixed a bug where `craftpulse\auditkit\helpers\PluginAdoption::adopt()` left its project config removal to the end of the request, so an adoption migration running in a process that never completes one, such as a test harness or a console process that exits early, could finish with the `plugins` table row deleted and the `plugins.audit-kit` project config entry still present.
- Fixed a bug where `craftpulse\auditkit\helpers\PluginAdoption::adopt()` only checked the loaded project config, so a `plugins.audit-kit` entry that existed in the project config YAML alone was left there, where the next external apply treats it as a plugin that still needs installing.
- Fixed a bug where `craftpulse\auditkit\helpers\PluginAdoption::adopt()` deleted the `plugins` table row before removing the project config entry, so a failure part way through left the install with no `plugins` row and a project config entry still naming the plugin.

## 1.1.0 - 2026-08-02

> [!IMPORTANT]
> Audit Kit is now a library-shipped Yii module, not a Craft plugin. It no longer appears in Craft's installed-plugins list and can no longer be enabled or disabled. Consuming plugins must call `AuditKit::register()` from their own `init()` and ship the one-off `PluginAdoption::adopt()` migration. See "Adopting 1.1.0 from the plugin era" in the README for the full retrofit checklist. The `AuditEvent` contract, the canonicalization recipe, and the chain-engine byte contract are unchanged and remain frozen.

### Added
- `AuditKit::register()` - the idempotent module registration entry point. Every consuming plugin calls it; the first call registers the module and every later call returns the same instance, so any number of consumers can call it on one install with no ordering requirement.
- `AuditKit::getInstance()` - registers the module on first use and returns it, so migrations and other early-boot contexts never depend on a consumer's `init()` having run first.
- `AuditKit::getMigrator()` - a kit-owned migration manager on the `module:audit-kit` track. Consumers pump it from their own `Install` migration with `AuditKit::getInstance()->getMigrator()->up()`. No kit migrations ship today; the seam exists so a future one reaches every install without a coordinated release across every consumer.
- `helpers\PluginAdoption::adopt()` - the idempotent plugin-era adoption helper. It marks plugin-era migration history as applied on the module track, deletes the `audit-kit` row from the `plugins` table, and removes the `plugins.audit-kit` project config entry with project config events muted and read-only temporarily lifted. It never touches kit or consumer tables, and it is a clean no-op on installs that never had the plugin and on repeat runs.

### Changed
- The Composer package type is now `library` instead of `craft-plugin`, and the plugin `extra` block is gone.
- `AuditKit` extends `yii\base\Module` instead of `craft\base\Plugin`. The `bus` and `eventTypes` components are attached by the module itself rather than merged in through Craft's plugin config, and the Auth Kit bridge is wired during module registration rather than on `Craft::$app->onInit()`.
- The test harness registers the module the way a consumer does instead of installing a plugin, and it asserts that Audit Kit never appears in the plugins list.

### Removed
- `base\PluginTrait`. Its only responsibility, wiring the Auth Kit bridge, moved into module registration.
- `ServicesTrait::config()`, the Craft plugin component-config hook, replaced by direct component attachment on the module.

## 1.0.1 - 2026-07-30

### Changed
- The test suite now runs against its own `db_test` schema with the plugin installed under test, and CI runs `check-cs` plus the full Pest suite on every push.

### Fixed
- `ChainWriter::write()` no longer loses audit events under concurrent write load. A MySQL serialization failure on the tail-read or on the persist closure's insert (deadlock 1213/`SQLSTATE 40001`, or a lock-wait timeout 1205) previously propagated uncaught; live 12-way and 20-way concurrent-write reproductions had 75% and 90% of writers crashing on the first deadlock hit. Writes are now retried a bounded number of times with full-jitter exponential backoff, and each attempt re-reads the chain tail and recomputes `previousHash`/`rowHash` inside a fresh transaction, so nothing is reused across attempts. Once the retry budget is exhausted, `write()` throws the new `craftpulse\auditkit\errors\ChainWriteRetriesExhaustedException`, which callers can catch to requeue the write.

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
