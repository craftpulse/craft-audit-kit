# Retrofitting a consumer

This is the complete checklist for moving a plugin that depends on Audit Kit 1.0.x, where the kit shipped as a Craft plugin, onto 1.1.x, where it ships as a library-shipped Yii module.

It is three mechanical changes and three verification steps, and it applies unchanged to every consumer. Work through it in order.

If you are adding Audit Kit to a plugin for the first time, you do not need this page. Follow [Installation and setup](../get-started/installation-setup.md) instead and skip the adoption migration entirely.

## What actually changes

The kit's Composer type changes from `craft-plugin` to `library`. After `composer update`, Craft stops discovering Audit Kit as an installable plugin, which means two things stop happening on their own:

- Craft no longer boots the kit for you. Nothing constructs the module, so the bus does not exist until a consumer calls `AuditKit::register()`.
- The install's existing `plugins` table row and `plugins.audit-kit` project config entry now describe a package Craft can no longer discover, and nothing removes them.

Steps 2 and 3 below fix those two things respectively. Step 1 is housekeeping.

What does not change: the `AuditEvent` contract, the canonicalization recipe, and the chain-engine byte format are all frozen and untouched. Your existing chains, retention, exports, and anchors are unaffected, `AuditKit::$plugin` still resolves, and log categories still read `audit-kit`. The 1.1.0 conversion changes how the kit is bootstrapped, never what it serializes.

## 1. Bump the dependency

In your plugin's `composer.json`:

```json
"require": {
    "craftpulse/craft-audit-kit": "^1.1.2"
}
```

The package name is unchanged. Only the constraint moves.

Require 1.1.2 or newer, not 1.1.0. The single-call retrofit below depends on `PluginAdoption::adopt()` pumping the kit migrator itself, which it does from 1.1.2 onwards. On 1.1.0 or 1.1.1 the same code resolves and runs, and the pump silently does not happen.

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

## 3. Ship the adoption migration

`PluginAdoption::adopt()` is the whole contract. There is no second call to remember: it sheds the plugin-era registration and brings the kit's own migration track up to date, so there is no way to retrofit half of it.

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

### Call it from your `Install` migration too

The dated migration above covers installs that already have your plugin. It does not cover a fresh install, because Craft stamps dated migrations as applied *without running them* when your plugin installs for the first time. So make the same call from `Install::safeUp()`:

```php
public function safeUp(): bool
{
    \craftpulse\auditkit\helpers\PluginAdoption::adopt();

    // ... your own schema

    return true;
}
```

A fresh install of your plugin is not the same thing as a clean database. A site that once ran the 1.0.x Audit Kit plugin still has the `audit-kit` row in its `plugins` table and the `plugins.audit-kit` project config entry, and on that site the dated migration never gets the chance to clear them. Nothing else would, and the stale registration would keep Audit Kit listed as a plugin indefinitely.

Both call sites are the same one-liner. `adopt()` is a no-op wherever its work is already done, so an install that reaches both is not doing the work twice.

Do not add a matching `getMigrator()->down()` to your `safeDown()`, and do not reach for anything that would tear the kit down. The kit is shared by every installed consumer, and one plugin's uninstall must not remove state the others still rely on.

### What `adopt()` does

In order:

1. Marks any plugin-era migration history on the `plugin:audit-kit` track as applied on the `module:audit-kit` track, so the module migrator never re-runs a migration the plugin era already applied, then deletes the plugin-track rows. The synthetic `Install` row Craft records for plugin installs is dropped rather than copied, because it names no migration class on the module track.
2. Removes the `plugins.audit-kit` project config entry, with project config events muted and read-only temporarily lifted, mirroring what Craft's own forced plugin uninstall does. Muting matters: nothing may react to the removal as if a real uninstall were happening. Both the loaded config and the external YAML are checked, because an entry left behind in YAML is treated as a plugin that still needs installing on the next external apply.
3. Deletes the `audit-kit` row from the `plugins` table.
4. Runs the kit migrator's `up()` on the `module:audit-kit` track, applying anything genuinely pending: everything on an install that has never pumped the kit, only the unapplied deltas on a partially updated one, and nothing at all when the track is already current.

The database row goes last of the removal steps on purpose. If the project config step fails, the registration is still in place and the next adoption call retries the whole removal, rather than leaving an install whose `plugins` row is gone while project config still names the plugin.

It never touches kit or consumer tables. Audit chains, exports, and anchors are untouched.

### The migrator pump is inside `adopt()`

Step 4 is why one call is enough, and it is worth understanding rather than taking on trust.

Audit Kit ships no migrations today, so the pump applies nothing on any install right now. The seam is the point: it is what lets a future kit migration reach every install without a coordinated release across every consumer. Because it lives inside `adopt()`, a consumer gets it by shipping the retrofit, and there is no separate call whose absence would go unnoticed until a kit migration actually shipped.

That also makes `adopt()` a strict superset of the bare `AuditKit::getInstance()->getMigrator()->up()`. On an install that never had the plugin, every removal step finds nothing and the call degrades to exactly the `up()` it replaces.

**An explicit `getMigrator()->up()` in your `Install::safeUp()` remains correct and harmless.** If you retrofitted against an earlier version of this guide and already have one, leave it. `MigrationManager::up()` applies only what `getNewMigrations()` reports, and that filters the migration directory against the recorded history, so a migration the explicit call already applied is never a candidate again and the pump finds nothing to do. Keeping it costs a redundant query. Removing it is fine too, as long as the `adopt()` call in `Install::safeUp()` replaces it.

### The removal is durable before `adopt()` returns

You do not need to flush project config yourself, and you should not try to.

`ProjectConfig::set()` commits to the loaded working config and leaves persistence to the end of the request, which a migration cannot count on reaching: a console process that exits early, or a test harness that boots Craft without a request lifecycle, drops the change. So `adopt()` flushes the removal itself, with events still muted, before it returns. Once the call comes back, the entry is written out of the stored config and, on installs that write YAML automatically, out of the YAML files.

The one case where the YAML is deliberately left alone is a run with external changes already pending: Craft turns automatic YAML writing off for the whole migration so a migration cannot clobber your incoming changes. The stored config is still updated. That is what the `project-config/diff` check below is for.

### It is safe in every combination

Every step is guarded, so:

- Every consumer ships the same one-liner. The first migration to run does the work; the rest find nothing to do and no-op.
- On an install that never had the plugin, including a fresh module-era install, the removal steps all find nothing and the call is just the migrator pump.
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

There must be no pending change under `plugins.audit-kit`. An entry still showing here means step 2 could not write the YAML, which is what happens when the migration ran while other project config changes were already pending: Craft turns automatic YAML writing off for the whole migration run.

Fix it by deleting the `plugins.audit-kit` block from `config/project/project.yaml` and committing that alongside the rest of your retrofit. Nothing re-adds it, because the package is no longer a plugin. Leave it in place and the next `project-config/apply` treats it as a plugin that still needs installing.

Run this on every environment you deploy to, not just one. Project config is per-environment state and a stale entry on staging is a real deploy failure waiting to happen.

### Chains are still writing

Trigger an event your plugin audits, then confirm a new row landed on its chain and that the chain still verifies.

This is the step that catches a missing `AuditKit::register()`. With the module unregistered, `record()` is never reached, and nothing fails loudly: your plugin keeps working and silently stops auditing. Neither the plugins list nor `project-config/diff` will tell you.

Verify rather than assume. Write one event, read the row back, and run your verification command over the chain.

## Rolling out across several consumers

If more than one of your plugins consumes Audit Kit, retrofit each one independently. There is no shared state to coordinate, no required order, and no window during which a partially retrofitted install misbehaves: a consumer that has been retrofitted registers the module, and one that has not yet been retrofitted finds it already registered when it eventually is.

The one thing worth doing in a single pass is the verification, on a real install with every consumer deployed. That is where a missing `register()` in one plugin shows up as a chain that stopped growing.
