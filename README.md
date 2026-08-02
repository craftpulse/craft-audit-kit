# Audit Kit

Foundational, tamper-evident audit primitives for Craft CMS 5: the shared
compliance base for the CraftPulse ecosystem, the way Auth Kit is the shared
identity base.

Audit Kit ships **no tables, no control-panel UI, no settings, and no
editions**. It is contract + engine + one bus. Consuming plugins (Ledger,
Password Policy, Reeve, Loadout) `require` it and parameterise the engine with
their own tables, payloads, and storage.

## Audit Kit is a module, not a plugin

Since **1.1.0** Audit Kit ships as a Composer **library** that registers itself
as a **Yii module**, not as a Craft plugin. It never appears in Craft's
installed-plugins list, has no Settings entry, and cannot be enabled or
disabled. A base kit is infrastructure that consuming plugins depend on: it
should no more be switchable than the autoloader is.

Consumers bootstrap it with a single idempotent call:

```php
use craftpulse\auditkit\AuditKit;

AuditKit::register();
```

Every consuming plugin makes that call. On an install running thirteen
CraftPulse plugins, the first call constructs and attaches the module and the
remaining twelve find it already registered and return it. There is no
ordering requirement and no coordination between consumers.

Upgrading from 1.0.x? See
[Adopting 1.1.0 from the plugin era](#adopting-110-from-the-plugin-era).

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

## Consumer wiring contract

Three entry points, and nothing else, are what a consuming plugin touches.

| Entry point | Signature | Call it from |
|---|---|---|
| Registration | `AuditKit::register(): AuditKit` | your plugin's `init()` |
| Instance access | `AuditKit::getInstance(): AuditKit` | anywhere, including migrations |
| Migrator | `AuditKit::getInstance()->getMigrator(): MigrationManager` | your `Install` migration |
| Plugin-era adoption | `PluginAdoption::adopt(): void` | a one-off retrofit migration |

`AuditKit::$plugin` remains available and is set the moment the module
registers, so existing `AuditKit::$plugin->getBus()` call sites keep working
unchanged. `AuditKit::getInstance()` is the safer form in migrations and other
early-boot contexts: it registers the module on first use rather than assuming
someone else already did.

### 1. Register the module

```php
use craftpulse\auditkit\AuditKit;

public function init(): void
{
    parent::init();

    AuditKit::register();

    // ... your own wiring
}
```

Registration is idempotent, cheap, and safe to call before or after any other
consumer does. Call it unconditionally; do not guard it with a
`Craft::$app->getModule()` check of your own.

### 2. Pump the migrator from your Install migration

Audit Kit owns its migrations on the `module:audit-kit` track, separate from
your plugin's own `plugin:<handle>` track. Consumers pump it the way Formie
pumps verbb/auth:

```php
use craftpulse\auditkit\AuditKit;

public function safeUp(): bool
{
    AuditKit::getInstance()->getMigrator()->up();

    // ... your own schema
    return true;
}
```

The kit ships no migrations today, so this is currently a no-op. Wire it
anyway: it is the seam that lets a future kit migration reach every install
without a coordinated release across thirteen plugins.

**Never call `getMigrator()->down()` from your `safeDown()`.** The kit is
shared by every installed consumer, and one plugin's uninstall must not tear
down state the other twelve still rely on.

### 3. Bus and registries

Unchanged from 1.0.x:

```php
AuditKit::getInstance()->getBus()->record($auditEvent);
AuditKit::getInstance()->getEventTypes()->get($name);
```

The `AuditEvent` contract, the canonicalization recipe, and the chain-engine
byte contract are **frozen**. The 1.1.0 conversion changes how the kit is
bootstrapped, never what it serializes.

## Adopting 1.1.0 from the plugin era

Retrofit checklist for a plugin currently depending on Audit Kit 1.0.x. It is
four mechanical steps and applies unchanged to every consumer.

**1. Bump the dependency** in your `composer.json`:

```json
"require": {
    "craftpulse/craft-audit-kit": "^1.1.0"
}
```

Audit Kit's Composer type changes from `craft-plugin` to `library` in 1.1.0,
so after `composer update` Craft stops discovering it as an installable
plugin. The package name is unchanged.

**2. Register the module** in your plugin's `init()`:

```php
AuditKit::register();
```

If you previously relied on Craft booting the audit-kit plugin for you, this
call replaces that entirely. Without it the bus is never constructed.

**3. Pump the kit migrator** from your `Install` migration's `safeUp()`:

```php
AuditKit::getInstance()->getMigrator()->up();
```

**4. Ship a one-off adoption migration** so existing installs shed the
plugin-era registration:

```php
use craftpulse\auditkit\helpers\PluginAdoption;

public function safeUp(): bool
{
    PluginAdoption::adopt();

    return true;
}
```

`adopt()` marks plugin-era migration history as applied on the module track,
deletes the `audit-kit` row from the `plugins` table, and removes the
`plugins.audit-kit` project config entry with project config events muted and
read-only temporarily lifted. It **never touches kit or consumer tables**:
audit chains, exports, and anchors are untouched.

It is idempotent and safe in every combination:

- All thirteen consumers ship the same one-liner. The first to run does the
  work; the rest are no-ops.
- On an install that never had the plugin (a fresh 1.1.0 install), every step
  finds nothing and returns cleanly.
- Running it twice, or after a partial run, converges to the same state.

Ship it in whichever consumer you retrofit first, and in all the others too.
There is no need to coordinate which plugin "owns" the adoption.

### What does not change

- `AuditKit::$plugin` still resolves; existing call sites compile and run.
- The `AuditEvent` contract, canonicalization, and chain byte format are frozen.
- Consumer chain tables, retention, exports, and anchors are untouched.
- Log categories still read `audit-kit`.

### Verifying a retrofit

After deploying a retrofitted consumer:

- **Settings, Plugins** no longer lists Audit Kit.
- `php craft project-config/diff` shows no pending `plugins.audit-kit` change.
- Your plugin's audit events still land in its chain (write one and verify it).

## Auth Kit bridge

With Auth Kit present, `integrations\AuthKitBridge` relays every authentication
event onto the audit bus (`Audit::EVENT_AFTER_RECORD` to `AuditEvent`, category
`auth`), so recorders wire a single seam. Auth Kit is `suggest`ed, never
`require`d, and the bridge is a clean no-op when it is absent. The bridge is
wired during module registration, so it is live as soon as any consumer has
called `AuditKit::register()`.

## Requirements

- Craft CMS 5.0.0+
- PHP 8.2+
- Bootstrapped by a consuming plugin calling `AuditKit::register()`; the kit is
  a library-shipped module and is never installed on its own.
- `dompdf/dompdf` (PDF export + certificates)
- `aws/aws-sdk-php` is `suggest`ed for the S3 forwarder and S3 Object Lock
  anchor provider.

## License

MIT
