# Audit Kit

Audit Kit gives Craft plugins a shared, tamper-evident audit foundation: a neutral audit event contract, a dispatch bus, and a byte-deterministic SHA-256 hash-chain engine, so every plugin on an install writes to one verifiable trail instead of inventing its own.

Audit Kit is a library-shipped Yii module, not a Craft plugin. It has no Plugin Store entry, never appears in Craft's installed-plugins list, has no control panel surface, and cannot be enabled or disabled. Its audience is developers building plugins that consume it.

## Features

- A neutral audit event contract shared by every consuming plugin, with its shape frozen at 1.0.0.
- A dispatch bus that fans one recorded event out to every registered sink, isolating each sink from the others.
- A runtime event-type registry with fail-closed allowlists for `details` keys.
- A byte-deterministic canonicalizer and hash-chain writer, with serialized writes retried under contention.
- Chain verification with first-divergence reporting and retention-boundary tolerance.
- Streaming export to CSV, JSONL, JSON, HTML, and PDF.
- SIEM delivery over RFC 5424 syslog, signed webhooks, HTTP JSON, and S3.
- External anchoring through RFC 3161 timestamping and S3 Object Lock, with signed certificates of integrity.
- A standalone chain verifier that runs without Craft or Composer.
- An Auth Kit bridge that relays authentication events onto the audit bus.

## Requirements

### Craft CMS

Audit Kit requires Craft CMS 5.0.0 or greater.

### PHP

Audit Kit requires PHP 8.2 or greater.

### A consuming plugin

Audit Kit is never installed on its own. A consuming plugin requires the package and bootstraps the module by calling `AuditKit::register()`.

## Installation

Audit Kit is a library package. There is no Plugin Store entry and no `plugin/install` step.

You can add the package to your plugin using Composer and the command line.

1. Open your terminal and go to your plugin:

```shell
cd /path/to/plugin
```

2. Tell Composer to require the package:

```shell
composer require craftpulse/craft-audit-kit
```

Or add it as a requirement in your plugin's `composer.json` directly:

```json
"require": {
    "craftcms/cms": "^5.0.0",
    "craftpulse/craft-audit-kit": "^1.1.0"
}
```

### DDEV

If your project runs in DDEV, run the same command through DDEV from the project root:

```shell
ddev composer require craftpulse/craft-audit-kit
```

### Next steps

Requiring the package is not enough on its own. Audit Kit does nothing until a consuming plugin wires it up:

- Call `AuditKit::register()` from your plugin's `init()`.
- Call `AuditKit::getInstance()->getMigrator()->up()` from your `Install` migration's `safeUp()`.
- Register a sink, or emit events onto the bus, or both.

See [Installation and setup](docs/get-started/installation-setup.md) for the full wiring.

## Documentation

Full documentation lives in [docs/](docs/README.md).

## Licensing

Audit Kit is released under the MIT License. See [LICENSE.md](LICENSE.md) for the full text.

## Support

File issues at [github.com/craftpulse/craft-audit-kit/issues](https://github.com/craftpulse/craft-audit-kit/issues), or email support@craft-pulse.com.
