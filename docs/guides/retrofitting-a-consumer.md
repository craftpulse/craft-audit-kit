# Retrofitting a consumer

This is the complete checklist for moving a plugin that depends on Audit Kit 1.0.x, where the kit shipped as a Craft plugin, onto 1.1.0, where it ships as a library-shipped Yii module.

It is four mechanical changes and three verification steps, and it applies unchanged to every consumer. Work through it in order.

If you are adding Audit Kit to a plugin for the first time, you do not need this page. Follow [Installation and setup](../get-started/installation-setup.md) instead and skip the adoption migration entirely.

## What actually changes

The kit's Composer type changes from `craft-plugin` to `library`. After `composer update`, Craft stops discovering Audit Kit as an installable plugin, which means two things stop happening on their own:

- Craft no longer boots the kit for you. Nothing constructs the module, so the bus does not exist until a consumer calls `AuditKit::register()`.
- The install's existing `plugins` table row and `plugins.audit-kit` project config entry now describe a package Craft can no longer discover, and nothing removes them.

Steps 2 and 4 below fix those two things respectively. Steps 1 and 3 are housekeeping.

What does not change: the `AuditEvent` contract, the canonicalization recipe, and the chain-engine byte format are all frozen and untouched. Your existing chains, retention, exports, and anchors are unaffected, `AuditKit::$plugin` still resolves, and log categories still read `audit-kit`. The 1.1.0 conversion changes how the kit is bootstrapped, never what it serializes.

## 1. Bump the dependency

In your plugin's `composer.json`:

```json
"require": {
    "craftpulse/craft-audit-kit": "^1.1.0"
}
```

The package name is unchanged. Only the constraint moves.

## 2. Register the module

In your plugin's `init()`:

```php
use craftpulse\auditkit\AuditKit;

public function init(): void
{
    parent::init();

    AuditKit::register();

    // ... your own wiring
}
```

If you previously relied on Craft booting the audit-kit plugin, this call replaces that entirely. Without it, the bus is never constructed and nothing you emit is recorded.

Call it unconditionally. It is idempotent, it is cheap, and it is safe before or after any other consumer calls it. Do not guard it with a `Craft::$app->getModule()` check of your own.

## 3. Pump the kit migrator

In your plugin's `Install` migration's `safeUp()`:

```php
public function safeUp(): bool
{
    \craftpulse\auditkit\AuditKit::getInstance()->getMigrator()->up();

    // ... your own schema

    return true;
}
```

This affects fresh installs of your plugin, not the install you are retrofitting, whose `Install` already ran. Wire it now anyway so the next fresh install is correct.

The kit ships no migrations today, so the call is currently a no-op. It is the seam that lets a future kit migration reach every install without a coordinated release across every consumer.

Do not add a matching `getMigrator()->down()` to your `safeDown()`. The kit is shared by every installed consumer, and one plugin's uninstall must not tear down state the other twelve still rely on.

## 4. Ship the adoption migration

Add a new plugin migration so existing installs shed the plugin-era registration:

```php
<?php

namespace mynamespace\migrations;

use craft\db\Migration;
use craftpulse\auditkit\helpers\PluginAdoption;

/**
 * m260802_120000_adopt_audit_kit_module migration.
 */
class m260802_120000_adopt_audit_kit_module extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        PluginAdoption::adopt();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m260802_120000_adopt_audit_kit_module cannot be reverted.\n";

        return false;
    }
}
```

This is a plugin migration on your own track, not a content migration and not an addition to `Install`. It has to run on update, which is exactly what a plugin migration does and what `Install` does not.

It is irreversible by design. Reverting would mean reinstating a plugin registration for a package that is no longer a plugin, which is not a state worth being able to return to.

### What `adopt()` does

In order:

1. Marks any plugin-era migration history on the `plugin:audit-kit` track as applied on the `module:audit-kit` track, so the module migrator never re-runs a migration the plugin era already applied, then deletes the plugin-track rows. The synthetic `Install` row Craft records for plugin installs is dropped rather than copied, because it names no migration class on the module track.
2. Deletes the `audit-kit` row from the `plugins` table.
3. Removes the `plugins.audit-kit` project config entry, with project config events muted and read-only temporarily lifted, mirroring what Craft's own forced plugin uninstall does. Muting matters: nothing may react to the removal as if a real uninstall were happening.

It never touches kit or consumer tables. Audit chains, exports, and anchors are untouched.

### It is safe in every combination

Every step is guarded, so:

- All thirteen consumers ship the same one-liner. The first migration to run does the work; the rest find nothing to do and no-op.
- On an install that never had the plugin, including a fresh 1.1.0 install, every step finds nothing and returns cleanly.
- Running it twice, or after a partial run, converges to the same state.

Ship it in whichever consumer you retrofit first, and in all the others too. There is no need to decide which plugin owns the adoption, and no coordination between consumers.

## Verify the retrofit

Do all three. The first two catch a missing adoption migration; the third catches a missing `register()` call, which the first two cannot see.

### The kit is gone from the plugins list

Go to **Settings** → **Plugins** in the control panel. Audit Kit must not be listed, in any state.

If it is still listed, the adoption migration has not run. Check your plugin's migration history and confirm the new migration applied.

### Project config is clean

```shell
php craft project-config/diff
```

Or through DDEV:

```shell
ddev craft project-config/diff
```

There must be no pending change under `plugins.audit-kit`. An entry still showing here means step 3 of `adopt()` did not complete, usually because project config was read-only at the time and the lift failed.

Run this on every environment you deploy to, not just one. Project config is per-environment state and a stale entry on staging is a real deploy failure waiting to happen.

### Chains are still writing

Trigger an event your plugin audits, then confirm a new row landed on its chain and that the chain still verifies.

This is the step that catches a missing `AuditKit::register()`. With the module unregistered, `record()` is never reached, and nothing fails loudly: your plugin keeps working and silently stops auditing. Neither the plugins list nor `project-config/diff` will tell you.

Verify rather than assume. Write one event, read the row back, and run your verification command over the chain.

## Rolling out across several consumers

If more than one of your plugins consumes Audit Kit, retrofit each one independently. There is no shared state to coordinate, no required order, and no window during which a partially retrofitted install misbehaves: a consumer that has been retrofitted registers the module, and one that has not yet been retrofitted finds it already registered when it eventually is.

The one thing worth doing in a single pass is the verification, on a real install with every consumer deployed. That is where a missing `register()` in one plugin shows up as a chain that stopped growing.
