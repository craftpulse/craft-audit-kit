<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Integration coverage for the plugin-era adoption helper: on an install that
 * still carries the 1.0.x plugin registration, `PluginAdoption::adopt()` must
 * move plugin-track migration history onto the module track (dropping the
 * synthetic `Install` row), remove the project config entry with events muted,
 * and delete the plugins-table row - in that order, durably, and without
 * touching anything that belongs to another plugin.
 *
 * The project config assertions here deliberately read the *stored* config
 * (the `projectconfig` table and the YAML on disk) rather than the loaded
 * working copy. `ProjectConfig::remove()` mutates the working copy and defers
 * persistence to `EVENT_AFTER_REQUEST`, so an assertion against the working
 * copy passes whether or not the removal was ever written down - which is
 * exactly how a missing `flush()` stayed invisible.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\services\ProjectConfig;
use craftpulse\auditkit\helpers\PluginAdoption;
use Symfony\Component\Yaml\Yaml;
use yii\base\Event;

/**
 * @var string A second plugin's handle, used to prove every delete the helper
 * issues is scoped to Audit Kit's own registration.
 */
const FOREIGN_HANDLE = 'audit-kit-test-consumer';

/**
 * Returns the project config path a plugin registers itself under.
 */
function pluginConfigPath(string $handle = PluginAdoption::PLUGIN_HANDLE): string
{
    return ProjectConfig::PATH_PLUGINS . '.' . $handle;
}

/**
 * Seeds a plugin-era registration into the test database: a plugins table row
 * plus plugin-track migration history (the synthetic `Install` row and one
 * dated migration).
 */
function seedPluginEraRegistration(string $handle = PluginAdoption::PLUGIN_HANDLE): void
{
    Db::insert(Table::PLUGINS, [
        'handle' => $handle,
        'version' => '1.0.1',
        'schemaVersion' => '1.0.0',
        'installDate' => Db::prepareDateForDb(new DateTimeImmutable()),
    ]);

    foreach (['Install', "m260101_000000_{$handle}_fixture"] as $name) {
        Db::insert(Table::MIGRATIONS, [
            'track' => "plugin:$handle",
            'name' => $name,
            'applyTime' => Db::prepareDateForDb(new DateTimeImmutable()),
        ]);
    }
}

/**
 * Seeds a plugin-era project config entry and, unlike a bare `set()`, persists
 * it - so a test asserting on the stored config is asserting against a removal
 * rather than against an entry that was never written in the first place.
 */
function seedPluginEraConfigEntry(string $handle = PluginAdoption::PLUGIN_HANDLE): void
{
    $projectConfig = Craft::$app->getProjectConfig();

    $muteEvents = $projectConfig->muteEvents;
    $readOnly = $projectConfig->readOnly;
    $projectConfig->muteEvents = true;
    $projectConfig->readOnly = false;

    try {
        $projectConfig->set(pluginConfigPath($handle), [
            'edition' => 'standard',
            'enabled' => true,
            'schemaVersion' => '1.0.0',
        ]);
        $projectConfig->flush();
    } finally {
        $projectConfig->readOnly = $readOnly;
        $projectConfig->muteEvents = $muteEvents;
    }
}

/**
 * Seeds an external (YAML) config carrying a plugin-era entry the loaded config
 * does not have - the state a deploy leaves behind when the YAML still names
 * the plugin and the database no longer does.
 */
function seedExternalConfigWithPluginEntry(): void
{
    $projectConfig = Craft::$app->getProjectConfig();
    $file = Craft::$app->getPath()->getProjectConfigPath() . DIRECTORY_SEPARATOR . ProjectConfig::CONFIG_FILENAME;

    FileHelper::writeToFile($file, Yaml::dump([
        ProjectConfig::PATH_DATE_MODIFIED => $projectConfig->get(ProjectConfig::PATH_DATE_MODIFIED),
        ProjectConfig::PATH_PLUGINS => [
            PluginAdoption::PLUGIN_HANDLE => [
                'edition' => 'standard',
                'enabled' => true,
                'schemaVersion' => '1.0.0',
            ],
        ],
    ], 20, 2));

    // Drop the memoized external config and file list so the seeded file is
    // what the service reads next.
    $projectConfig->reset();
}

/**
 * Returns the migration names recorded on the given track.
 *
 * @return string[]
 */
function migrationNamesOnTrack(string $track): array
{
    return (new Query())
        ->select(['name'])
        ->from(Table::MIGRATIONS)
        ->where(['track' => $track])
        ->column();
}

/**
 * Returns whether the plugins table still carries a row for the given handle.
 */
function pluginRowExists(string $handle = PluginAdoption::PLUGIN_HANDLE): bool
{
    return (new Query())
        ->from(Table::PLUGINS)
        ->where(['handle' => $handle])
        ->exists();
}

/**
 * Returns the stored project config paths under a plugin's entry. This is the
 * persisted config, straight out of the database - not the loaded working copy.
 *
 * @return string[]
 */
function storedConfigPaths(string $handle = PluginAdoption::PLUGIN_HANDLE): array
{
    return (new Query())
        ->select(['path'])
        ->from(Table::PROJECTCONFIG)
        ->where(['or',
            ['path' => pluginConfigPath($handle)],
            ['like', 'path', pluginConfigPath($handle) . '.%', false],
        ])
        ->column();
}

/**
 * Returns the external project config as it currently sits on disk.
 *
 * @return array<string, mixed>
 */
function externalConfigOnDisk(): array
{
    $file = Craft::$app->getPath()->getProjectConfigPath(false) . DIRECTORY_SEPARATOR . ProjectConfig::CONFIG_FILENAME;

    if (!is_file($file)) {
        return [];
    }

    return (array)Yaml::parse((string)file_get_contents($file));
}

/**
 * Returns every table name in the test schema, sorted.
 *
 * @return string[]
 */
function schemaTableNames(): array
{
    $names = Craft::$app->getDb()->getSchema()->getTableNames('', true);
    sort($names);

    return $names;
}

beforeEach(function() {
    $projectConfig = Craft::$app->getProjectConfig();

    $this->configVersion = Craft::$app->getInfo()->configVersion;
    $this->writeYamlAutomatically = $projectConfig->writeYamlAutomatically;
    $this->externalConfigExisted = $projectConfig->getDoesExternalConfigExist();

    // Only the external-config test cares about YAML; the rest assert on the
    // database and have no business rewriting the install's config files.
    $projectConfig->writeYamlAutomatically = false;
});

afterEach(function() {
    $projectConfig = Craft::$app->getProjectConfig();
    $projectConfig->writeYamlAutomatically = $this->writeYamlAutomatically;

    $configPath = Craft::$app->getPath()->getProjectConfigPath(false);

    if (!$this->externalConfigExisted && is_dir($configPath)) {
        FileHelper::clearDirectory($configPath, ['except' => ['.*', '.*/']]);
    }

    // `flush()` rerolls the memoized configVersion; RefreshesDatabase rolls the
    // stored one back moments from now, and `ProjectConfig::_acquireLock()`
    // throws StaleResourceException on the next write when the two disagree.
    Craft::$app->getInfo()->configVersion = $this->configVersion;

    // Drop the memoized internal config, external config, and file list so no
    // seeded state leaks into the next test.
    $projectConfig->reset();
});

it('is a no-op on an install that never had the plugin', function() {
    expect(fn() => PluginAdoption::adopt())->not->toThrow(Throwable::class);

    expect(pluginRowExists())->toBeFalse();
    expect(migrationNamesOnTrack(PluginAdoption::PLUGIN_TRACK))->toBe([]);
    expect(storedConfigPaths())->toBe([]);

    // Nothing was written at all: `saveModifiedConfigData()` is the only thing
    // that rerolls configVersion, and `flush()` is the only way to reach it.
    expect(Craft::$app->getInfo()->configVersion)->toBe($this->configVersion);
});

it('adopts a plugin-era registration onto the module track', function() {
    seedPluginEraRegistration();

    PluginAdoption::adopt();

    // The plugins row is gone and the plugin track is empty.
    expect(pluginRowExists())->toBeFalse();
    expect(migrationNamesOnTrack(PluginAdoption::PLUGIN_TRACK))->toBe([]);

    // The dated migration is marked applied on the module track; the
    // synthetic Install row is dropped, not copied.
    expect(migrationNamesOnTrack(PluginAdoption::MODULE_TRACK))
        ->toBe(['m260101_000000_audit-kit_fixture']);
});

it('persists the project config removal without a request lifecycle', function() {
    seedPluginEraRegistration();
    seedPluginEraConfigEntry();

    // The seeded entry really is in the stored config, not just the loaded
    // working copy, so its absence below is a removal.
    expect(storedConfigPaths())->not->toBe([]);

    PluginAdoption::adopt();

    // Asserted the moment `adopt()` returns: no EVENT_AFTER_REQUEST, no
    // simulated request end. A console process that exits here, or a harness
    // that never runs a request lifecycle, must still find the entry gone.
    expect(storedConfigPaths())->toBe([]);
    expect(Craft::$app->getProjectConfig()->get(pluginConfigPath()))->toBeNull();
});

it('removes a plugin-era entry that survives only in the external config', function() {
    $projectConfig = Craft::$app->getProjectConfig();
    $projectConfig->writeYamlAutomatically = true;

    seedExternalConfigWithPluginEntry();

    // The entry exists in the external config only. Left behind there, the next
    // external apply would restore the registration the adoption just shed.
    expect($projectConfig->get(pluginConfigPath()))->toBeNull();
    expect($projectConfig->get(pluginConfigPath(), true))->not->toBeNull();

    PluginAdoption::adopt();

    $external = externalConfigOnDisk();
    expect($external[ProjectConfig::PATH_PLUGINS][PluginAdoption::PLUGIN_HANDLE] ?? null)->toBeNull();

    $projectConfig->reset();
    expect($projectConfig->get(pluginConfigPath(), true))->toBeNull();
});

it('mutes project config item events across the removal and the flush', function() {
    seedPluginEraRegistration();
    seedPluginEraConfigEntry();

    $names = [
        ProjectConfig::EVENT_ADD_ITEM,
        ProjectConfig::EVENT_UPDATE_ITEM,
        ProjectConfig::EVENT_REMOVE_ITEM,
    ];

    $fired = [];
    $record = function() use (&$fired) {
        $fired[] = true;
    };

    foreach ($names as $name) {
        Event::on(ProjectConfig::class, $name, $record);
    }

    try {
        PluginAdoption::adopt();
    } finally {
        foreach ($names as $name) {
            Event::off(ProjectConfig::class, $name, $record);
        }
    }

    // Nothing may react to the removal as if a real uninstall were happening,
    // and the flush must not re-fire what the removal muted.
    expect($fired)->toBe([]);
});

it('restores the mute and read-only flags it lifted', function() {
    seedPluginEraRegistration();
    seedPluginEraConfigEntry();

    $projectConfig = Craft::$app->getProjectConfig();
    $readOnly = $projectConfig->readOnly;
    $projectConfig->readOnly = true;

    try {
        PluginAdoption::adopt();

        // Asserted before the restore below, which would otherwise be the thing
        // putting the flag back.
        expect($projectConfig->readOnly)->toBeTrue();
        expect($projectConfig->muteEvents)->toBeFalse();
    } finally {
        $projectConfig->readOnly = $readOnly;
    }

    // A read-only install is still adopted: the lift is what Craft's own forced
    // uninstall does.
    expect(storedConfigPaths())->toBe([]);
});

it('deletes the plugins row only after the project config entry is gone', function() {
    seedPluginEraRegistration();

    $projectConfig = Craft::$app->getProjectConfig();

    Craft::$app->set('projectConfig', new class() extends ProjectConfig {
        /**
         * @inheritdoc
         */
        public function get(?string $path = null, bool $getFromExternalConfig = false): mixed
        {
            throw new RuntimeException('Project config unavailable');
        }
    });

    try {
        expect(fn() => PluginAdoption::adopt())->toThrow(RuntimeException::class);
    } finally {
        Craft::$app->set('projectConfig', $projectConfig);
    }

    // The registration row outlived the failure, so the next adoption call
    // retries the whole removal instead of leaving a half-adopted install.
    expect(pluginRowExists())->toBeTrue();
});

it('is idempotent when every consumer runs the same adoption one-liner', function() {
    seedPluginEraRegistration();
    seedPluginEraConfigEntry();

    PluginAdoption::adopt();
    $configVersion = Craft::$app->getInfo()->configVersion;

    expect(fn() => PluginAdoption::adopt())->not->toThrow(Throwable::class);

    expect(migrationNamesOnTrack(PluginAdoption::MODULE_TRACK))
        ->toBe(['m260101_000000_audit-kit_fixture']);
    expect(storedConfigPaths())->toBe([]);
    expect(pluginRowExists())->toBeFalse();

    // The second run wrote nothing: a clean no-op, not merely a quiet one.
    expect(Craft::$app->getInfo()->configVersion)->toBe($configVersion);
});

it('touches nothing outside its own registration', function() {
    seedPluginEraRegistration();
    seedPluginEraConfigEntry();
    seedPluginEraRegistration(FOREIGN_HANDLE);
    seedPluginEraConfigEntry(FOREIGN_HANDLE);

    $tables = schemaTableNames();

    PluginAdoption::adopt();

    // No schema changes: the kit owns no tables, and consumer chain tables
    // belong to the consumers.
    expect(schemaTableNames())->toBe($tables);

    // Every delete is scoped to Audit Kit's own handle and track.
    expect(pluginRowExists(FOREIGN_HANDLE))->toBeTrue();
    expect(migrationNamesOnTrack('plugin:' . FOREIGN_HANDLE))
        ->toBe(['Install', 'm260101_000000_' . FOREIGN_HANDLE . '_fixture']);
    expect(storedConfigPaths(FOREIGN_HANDLE))->not->toBe([]);
});
