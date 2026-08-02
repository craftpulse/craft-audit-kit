# Audit Kit documentation

Audit Kit is a library package, not a Craft plugin. These pages are written for developers building plugins that consume it.

## Get Started

- [Installation and Setup](get-started/installation-setup.md): requiring the package, registering the module, and pumping the migrator from your `Install` migration.
- [Requirements](get-started/requirements.md): Craft, PHP, and the required and suggested dependencies.

## Feature Tour

- [Usage](feature-tour/usage.md): the whole flow end to end, from declaring an event type to writing a chain row, and which parts of it the kit owns.
- [Audit events](feature-tour/audit-events.md): the frozen `AuditEvent` contract, event types, and the fail-closed registry.
- [The bus](feature-tour/the-bus.md): recording events, writing a sink, and registering it.
- [The chain engine](feature-tour/chain-engine.md): canonicalization, chain writing, verification, context hashing, and pruning.
- [Export](feature-tour/export.md): streaming CSV, JSONL, JSON, HTML, and PDF export.
- [SIEM forwarding](feature-tour/siem.md): syslog over TLS, signed webhooks, HTTP JSON, S3, and the circuit breaker.
- [Anchoring](feature-tour/anchoring.md): Merkle batching, RFC 3161 and Object Lock anchoring, certificates, and the standalone verifier.
- [Events](feature-tour/events.md): every registration and lifecycle event, with working listeners.
- [The Auth Kit bridge](feature-tour/auth-kit-bridge.md): relaying Auth Kit authentication events onto the audit bus.

## Guides

- [Retrofitting a consumer](guides/retrofitting-a-consumer.md): the complete checklist for moving a plugin from Audit Kit 1.0.x to 1.1.0.

## Changelog

Release notes are in [CHANGELOG.md](../CHANGELOG.md).
