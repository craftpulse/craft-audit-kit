<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Integration coverage for the plugin-era adoption helper: on an install that
 * still carries the 1.0.x plugin registration, `PluginAdoption::adopt()` must
 * move plugin-track migration history onto the module track (dropping the
 * synthetic `Install` row), delete the plugins-table row, and remove the
 * project config entry with events muted - and it must be idempotent and a
 * clean no-op on installs that never had the plugin.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craftpulse\auditkit\helpers\PluginAdoption;

/**
 * Seeds a plugin-era Audit Kit registration into the test database: a plugins
 * table row plus plugin-track migration history (the synthetic `Install` row
 * and one dated migration).
 */
function seedPluginEraRegistration(): void
{
    Db::insert(Table::PLUGINS, [
        'handle' => PluginAdoption::PLUGIN_HANDLE,
        'version' => '1.0.1',
        'schemaVersion' => '1.0.0',
        'installDate' => Db::prepareDateForDb(new DateTimeImmutable()),
    ]);

    foreach (['Install', 'm260101_000000_plugin_era_fixture'] as $name) {
        Db::insert(Table::MIGRATIONS, [
            'track' => PluginAdoption::PLUGIN_TRACK,
            'name' => $name,
            'applyTime' => Db::prepareDateForDb(new DateTimeImmutable()),
        ]);
    }
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

it('is a no-op on an install that never had the plugin', function() {
    expect(fn() => PluginAdoption::adopt())->not->toThrow(Throwable::class);

    expect((new Query())->from(Table::PLUGINS)->where(['handle' => PluginAdoption::PLUGIN_HANDLE])->exists())->toBeFalse();
    expect(migrationNamesOnTrack(PluginAdoption::PLUGIN_TRACK))->toBe([]);
});

it('adopts a plugin-era registration onto the module track', function() {
    seedPluginEraRegistration();

    PluginAdoption::adopt();

    // The plugins row is gone and the plugin track is empty.
    expect((new Query())->from(Table::PLUGINS)->where(['handle' => PluginAdoption::PLUGIN_HANDLE])->exists())->toBeFalse();
    expect(migrationNamesOnTrack(PluginAdoption::PLUGIN_TRACK))->toBe([]);

    // The dated migration is marked applied on the module track; the
    // synthetic Install row is dropped, not copied.
    expect(migrationNamesOnTrack(PluginAdoption::MODULE_TRACK))
        ->toBe(['m260101_000000_plugin_era_fixture']);
});

it('is idempotent when every consumer runs the same adoption one-liner', function() {
    seedPluginEraRegistration();

    PluginAdoption::adopt();
    PluginAdoption::adopt();

    expect(migrationNamesOnTrack(PluginAdoption::MODULE_TRACK))
        ->toBe(['m260101_000000_plugin_era_fixture']);
});

it('removes the plugin-era project config entry with events muted', function() {
    $projectConfig = Craft::$app->getProjectConfig();
    $path = 'plugins.' . PluginAdoption::PLUGIN_HANDLE;

    // Seed a plugin-era project config entry the way the plugin era left it,
    // muted so nothing reacts to a plugin that no longer exists.
    $muteEvents = $projectConfig->muteEvents;
    $readOnly = $projectConfig->readOnly;
    $projectConfig->muteEvents = true;
    $projectConfig->readOnly = false;

    try {
        $projectConfig->set($path, ['edition' => 'standard', 'enabled' => true, 'schemaVersion' => '1.0.0']);
    } finally {
        $projectConfig->readOnly = $readOnly;
        $projectConfig->muteEvents = $muteEvents;
    }

    expect($projectConfig->get($path))->not->toBeNull();

    PluginAdoption::adopt();

    expect($projectConfig->get($path))->toBeNull();

    // And the flags were restored.
    expect($projectConfig->muteEvents)->toBe($muteEvents);
    expect($projectConfig->readOnly)->toBe($readOnly);
});
