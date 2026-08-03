<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\helpers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\services\ProjectConfig;
use craftpulse\auditkit\AuditKit;

/**
 * PluginAdoption migrates an install from the plugin-era Audit Kit (1.0.x,
 * `type: craft-plugin`) to the library-shipped module (1.1.0+). It is the
 * one-liner every consuming plugin ships in its retrofit migration:
 *
 * ```php
 * public function safeUp(): bool
 * {
 *     PluginAdoption::adopt();
 *     return true;
 * }
 * ```
 *
 * What it does, in order:
 *
 * 1. Marks any plugin-era migration history (`plugin:audit-kit` track) as
 *    applied on the module track (`module:audit-kit`), so the module migrator
 *    never re-runs a migration the plugin era already applied, then deletes
 *    the plugin-track rows. The synthetic `Install` row Craft records for
 *    plugin installs is dropped, not copied: it names no migration class on
 *    the module track.
 * 2. Removes the `plugins.audit-kit` project config entry with project config
 *    events muted and read-only temporarily lifted, mirroring what Craft's own
 *    `Plugins::uninstallPlugin($handle, force: true)` does. Muting matters:
 *    nothing may react to the removal as if a real uninstall were happening.
 *    The removal is flushed before the call returns, so it does not depend on
 *    a request lifecycle the migration may never reach.
 * 3. Deletes the `audit-kit` row from the `plugins` table, last of the removal
 *    steps, so a failure part-way through leaves a registration the next
 *    adoption call retries from. Kit tables are never touched (the kit owns
 *    none; consumer chain tables belong to the consumers).
 * 4. Runs the kit migrator's `up()` on the `module:audit-kit` track, applying
 *    anything genuinely pending: everything on an install that has never
 *    pumped the kit, only the unapplied deltas on a partially updated one, and
 *    nothing at all when the track is already current (no kit migrations ship
 *    today, so this is a no-op on every install right now). Added in 1.1.2.
 *
 * Step 4 is what makes this a strict superset of the bare
 * `AuditKit::getInstance()->getMigrator()->up()`, which is the whole point:
 * one call is the entire contract, so a consumer cannot retrofit correctly and
 * still silently miss the pump. A consumer that also calls `up()` from its own
 * `Install::safeUp()` (Herald and Password Policy both do) is unaffected:
 * `MigrationManager::up()` applies only what `getNewMigrations()` reports, and
 * that method filters the migration directory against the recorded history, so
 * an already-applied migration is never a candidate and the second call finds
 * nothing to do.
 *
 * Every step is guarded, so the helper is idempotent (all thirteen consumers
 * ship the same one-liner and all thirteen may run it on one install; the
 * first does the work, the rest no-op) and safe on installs that never had
 * the plugin, where it degrades to exactly the `up()` it replaces.
 *
 * @author CraftPulse
 * @since 1.1.0
 */
final class PluginAdoption
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The plugin-era handle being adopted.
     *
     * @since 1.1.0
     */
    public const PLUGIN_HANDLE = 'audit-kit';

    /**
     * @var string The plugin-era migration track.
     *
     * @since 1.1.0
     */
    public const PLUGIN_TRACK = 'plugin:' . self::PLUGIN_HANDLE;

    /**
     * @var string The module migration track plugin-era history is adopted
     * onto. Kept as a literal (rather than referencing
     * `AuditKit::MIGRATION_TRACK`) so the history adoption never needs the
     * module registered to run; the migrator pump that follows it registers the
     * module lazily through [[\craftpulse\auditkit\AuditKit::getInstance()]].
     *
     * @since 1.1.0
     */
    public const MODULE_TRACK = 'module:' . self::PLUGIN_HANDLE;

    /**
     * @var string The synthetic history row Craft records when a plugin is
     * installed; it names no migration class, so it is dropped rather than
     * copied onto the module track.
     *
     * @since 1.1.0
     */
    private const INSTALL_HISTORY_NAME = 'Install';

    // Static Methods
    // =========================================================================

    /**
     * Adopts the plugin-era Audit Kit registration into the module world and
     * brings the kit's migration track up to date. See the class docblock for
     * the exact steps. Idempotent and safe on installs that never had the
     * plugin, where it degrades to a plain migrator pump.
     *
     * This is the whole contract a consumer needs, and the only call its
     * retrofit migration makes:
     *
     * ```php
     * public function safeUp(): bool
     * {
     *     \craftpulse\auditkit\helpers\PluginAdoption::adopt();
     *
     *     return true;
     * }
     * ```
     *
     * @throws \Throwable if a database write or the project config removal
     * fails, or if a pending kit migration fails to apply; the caller's
     * migration should surface that as a failed migration
     *
     * @author CraftPulse
     * @since 1.1.0
     */
    public static function adopt(): void
    {
        self::_adoptMigrationHistory();
        self::_removeProjectConfigEntry();
        self::_deletePluginRow();

        // The pump, since 1.1.2: a consumer gets the catch-up for free rather
        // than having to remember a second call of its own.
        AuditKit::getInstance()->getMigrator()->up();
    }

    // Private Methods
    // =========================================================================

    /**
     * Marks plugin-track migration history as applied on the module track and
     * deletes the plugin-track rows.
     *
     * @throws \Throwable if a database write fails
     *
     * @author CraftPulse
     * @since 1.1.0
     */
    private static function _adoptMigrationHistory(): void
    {
        $db = Craft::$app->getDb();

        if (!$db->tableExists(Table::MIGRATIONS)) {
            return;
        }

        $pluginEraNames = (new Query())
            ->select(['name'])
            ->from(Table::MIGRATIONS)
            ->where(['track' => self::PLUGIN_TRACK])
            ->column($db);

        if ($pluginEraNames === []) {
            return;
        }

        $moduleTrackNames = (new Query())
            ->select(['name'])
            ->from(Table::MIGRATIONS)
            ->where(['track' => self::MODULE_TRACK])
            ->column($db);

        foreach ($pluginEraNames as $name) {
            if ($name === self::INSTALL_HISTORY_NAME || in_array($name, $moduleTrackNames, true)) {
                continue;
            }

            Db::insert(Table::MIGRATIONS, [
                'track' => self::MODULE_TRACK,
                'name' => $name,
                'applyTime' => Db::prepareDateForDb(new \DateTimeImmutable()),
            ], $db);
        }

        Db::delete(Table::MIGRATIONS, ['track' => self::PLUGIN_TRACK], db: $db);
    }

    /**
     * Deletes the plugin-era row from the plugins table.
     *
     * @throws \Throwable if the database write fails
     *
     * @author CraftPulse
     * @since 1.1.0
     */
    private static function _deletePluginRow(): void
    {
        $db = Craft::$app->getDb();

        if (!$db->tableExists(Table::PLUGINS)) {
            return;
        }

        Db::delete(Table::PLUGINS, ['handle' => self::PLUGIN_HANDLE], db: $db);
    }

    /**
     * Removes the plugin-era project config entry with events muted and
     * read-only temporarily lifted, then restores both flags.
     *
     * Both the loaded config and the external (YAML) config are checked, and
     * the removal goes through `set(null, force: true)` rather than `remove()`,
     * because an entry left behind in YAML is treated as a plugin that still
     * needs installing on the next external apply. `remove()` compares the new
     * value against the loaded config only, so an entry that exists in YAML
     * alone reads as unchanged, is skipped, and never marks the YAML for a
     * rewrite; forcing it makes the flush regenerate the YAML without the entry.
     *
     * Project config events are muted across the removal and the flush. They
     * fire synchronously inside `set()` (see
     * [[\craft\models\ProjectConfigData::commitChanges()]]), so a mute covering
     * only the removal could still let the flush re-fire them, and nothing may
     * react to this as if a real uninstall were happening.
     *
     * The flush is explicit on purpose. `set()` commits to the loaded working
     * config and defers persistence to `EVENT_AFTER_REQUEST`, which a migration
     * cannot count on reaching: a console process that exits early, or a harness
     * that boots the app without a request lifecycle, would drop the change and
     * leave the plugin registered in project config even though the `plugins`
     * row is gone. Flushing here writes the config data and, on installs that
     * write YAML automatically, the YAML files, so the removal is durable the
     * moment this returns. Where Craft has deliberately turned automatic YAML
     * writing off because external changes are pending (see
     * [[\craft\console\controllers\MigrateController::runAction()]]), the flush
     * writes the config data only and the developer's YAML is left alone; the
     * retrofit guide's `project-config/diff` check covers that case.
     *
     * @throws \Throwable if the removal or the flush fails; the `plugins` row is
     * deleted after this returns, so a failure here leaves a registration the
     * next adoption call retries from
     *
     * @author CraftPulse
     * @since 1.1.0
     */
    private static function _removeProjectConfigEntry(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $path = ProjectConfig::PATH_PLUGINS . '.' . self::PLUGIN_HANDLE;

        $isRegistered = $projectConfig->get($path) !== null
            || $projectConfig->get($path, true) !== null;

        if (!$isRegistered) {
            return;
        }

        $muteEvents = $projectConfig->muteEvents;
        $readOnly = $projectConfig->readOnly;
        $projectConfig->muteEvents = true;
        $projectConfig->readOnly = false;

        try {
            $projectConfig->set(
                $path,
                null,
                sprintf('Adopt the "%s" plugin as a library-shipped module', self::PLUGIN_HANDLE),
                force: true,
            );

            $projectConfig->flush();
        } finally {
            $projectConfig->readOnly = $readOnly;
            $projectConfig->muteEvents = $muteEvents;
        }
    }
}
