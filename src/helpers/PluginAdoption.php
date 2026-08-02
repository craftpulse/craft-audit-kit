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
 * 2. Deletes the `audit-kit` row from the `plugins` table. Kit tables are
 *    never touched (the kit owns none; consumer chain tables belong to the
 *    consumers).
 * 3. Removes the `plugins.audit-kit` project config entry with project config
 *    events muted and read-only temporarily lifted, mirroring what Craft's own
 *    `Plugins::uninstallPlugin($handle, force: true)` does. Muting matters:
 *    nothing may react to the removal as if a real uninstall were happening.
 *
 * Every step is guarded, so the helper is idempotent (all thirteen consumers
 * ship the same one-liner and all thirteen may run it on one install; the
 * first does the work, the rest no-op) and safe on installs that never had
 * the plugin (fresh installs adopt nothing and no step fails).
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
     * `AuditKit::MIGRATION_TRACK`) so this helper never needs the module
     * registered to run.
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
     * Adopts the plugin-era Audit Kit registration into the module world. See
     * the class docblock for the exact steps. Idempotent and safe on installs
     * that never had the plugin.
     *
     * @throws \Throwable if a database write fails; the caller's migration
     * should surface that as a failed migration
     *
     * @author CraftPulse
     * @since 1.1.0
     */
    public static function adopt(): void
    {
        self::_adoptMigrationHistory();
        self::_deletePluginRow();
        self::_removeProjectConfigEntry();
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
     * @author CraftPulse
     * @since 1.1.0
     */
    private static function _removeProjectConfigEntry(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $path = ProjectConfig::PATH_PLUGINS . '.' . self::PLUGIN_HANDLE;

        if ($projectConfig->get($path) === null) {
            return;
        }

        $muteEvents = $projectConfig->muteEvents;
        $readOnly = $projectConfig->readOnly;
        $projectConfig->muteEvents = true;
        $projectConfig->readOnly = false;

        try {
            $projectConfig->remove($path, sprintf('Adopt the "%s" plugin as a library-shipped module', self::PLUGIN_HANDLE));
        } finally {
            $projectConfig->readOnly = $readOnly;
            $projectConfig->muteEvents = $muteEvents;
        }
    }
}
