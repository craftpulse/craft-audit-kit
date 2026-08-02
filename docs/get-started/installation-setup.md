# Installation and Setup

## Installation

You can add the package to your plugin using Composer, or as a requirement in your `composer.json` file directly:

```shell
composer require craftpulse/craft-audit-kit
```

```json
"require": {
    "craftcms/cms": "^5.0.0",
    "craftpulse/craft-audit-kit": "^1.1.0"
}
```

Audit Kit is a library package, not a Craft plugin. There is no Plugin Store entry and no `php craft plugin/install` step. It never appears in Craft's installed-plugins list, and site owners cannot enable or disable it.

If your project runs in DDEV, run the same command through DDEV from the project root:

```shell
ddev composer require craftpulse/craft-audit-kit
```

## Setup

To use Audit Kit in your plugin, call `AuditKit::register()` from your plugin's `init()`, then reach any service through `AuditKit::getInstance()`.

```php
use craftpulse\auditkit\AuditKit;

public function init(): void
{
    parent::init();

    AuditKit::register();

    // ...
}
```

`register()` is idempotent. The first call constructs the module, sets it on the application under the `audit-kit` module ID, and returns it. Every later call finds it already registered and returns the same instance. Every consuming plugin on an install calls it from its own `init()`, so however many consumers a site runs, the first call wins and the rest are cheap no-ops. There is no ordering requirement and no coordination between consumers.

Call it unconditionally. Do not guard it with a `Craft::$app->getModule()` check of your own, and do not try to work out whether another plugin has already called it.

`AuditKit::getInstance()` registers the module on first use and then returns it, so it is safe in migrations and other early-boot contexts where your plugin's `init()` may not have run yet.

`AuditKit::$plugin` is retained from the plugin era and is set the moment the module registers, so existing `AuditKit::$plugin->getBus()` call sites keep working unchanged. Prefer `AuditKit::getInstance()` in new code: it registers lazily rather than assuming someone else already did.

### Migrations

Audit Kit owns a migration manager on its own `module:audit-kit` track, separate from your plugin's `plugin:<handle>` track. In your plugin's `migrations\Install.php` file, add the following:

```php
class Install extends \craft\db\Migration
{
    public function safeUp(): bool
    {
        // Ensure that the Audit Kit module kicks off setting up tables
        \craftpulse\auditkit\AuditKit::getInstance()->getMigrator()->up();

        // Create any tables that your plugin requires
        $this->createTables();

        return true;
    }
}
```

This ensures any pending kit migrations are applied (and does nothing if another plugin requiring Audit Kit already applied them), ready for you to record events against.

Wire the call even when the kit has nothing pending to apply. It is the seam that lets a kit migration reach every install without a coordinated release across every consuming plugin.

Never call `getMigrator()->down()` from your `safeDown()`. The kit is shared by every installed consumer, and one plugin's uninstall must not tear down state the others still rely on. Your `safeDown()` drops your own tables and nothing else.

If you are including Audit Kit in a module rather than a plugin, you will not be able to make use of the `migrations\Install.php` migration that plugins have access to. Instead, you will want to call this through a content migration.

### Upgrading from 1.0.x

If your plugin already depends on Audit Kit 1.0.x, where the kit shipped as a Craft plugin, there is one more step: a one-off adoption migration that sheds the plugin-era registration. See [Retrofitting a consumer](../guides/retrofitting-a-consumer.md) for the complete checklist.
