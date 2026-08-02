<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Integration coverage for the 1.1.0 module model. These tests guard the
 * architectural ruling itself: Audit Kit registers as a Yii module, is never
 * a Craft plugin (so it cannot appear in the installed-plugins list and
 * cannot be enabled or disabled), and its registration entry point is safely
 * callable by every consumer on one install.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\auditkit\AuditKit;
use craftpulse\auditkit\services\Bus;
use craftpulse\auditkit\services\EventTypes;

it('registers as a Yii module under its own id', function() {
    $module = AuditKit::register();

    expect($module)->toBeInstanceOf(AuditKit::class);
    expect(Craft::$app->getModule(AuditKit::ID))->toBe($module);
});

it('never registers as a Craft plugin', function() {
    AuditKit::register();

    $plugins = Craft::$app->getPlugins();

    expect($plugins->isPluginInstalled(AuditKit::ID))->toBeFalse();
    expect($plugins->getPlugin(AuditKit::ID))->toBeNull();
    expect(array_keys($plugins->getAllPlugins()))->not->toContain(AuditKit::ID);
});

it('is idempotent across the many consumers that each call register()', function() {
    $first = AuditKit::register();

    // Thirteen estate consumers may all call this on one install.
    for ($i = 0; $i < 13; $i++) {
        expect(AuditKit::register())->toBe($first);
    }

    expect(AuditKit::getInstance())->toBe($first);
    expect(AuditKit::$plugin)->toBe($first);
});

it('exposes the bus and event-type registry as module components', function() {
    $module = AuditKit::register();

    expect($module->getBus())->toBeInstanceOf(Bus::class);
    expect($module->getEventTypes())->toBeInstanceOf(EventTypes::class);

    // Same instances on repeat access: consumers share one bus.
    expect($module->getBus())->toBe($module->getBus());
});

it('exposes a migrator on the module track', function() {
    $migrator = AuditKit::getInstance()->getMigrator();

    expect($migrator->track)->toBe('module:audit-kit');
    expect($migrator->track)->toBe(AuditKit::MIGRATION_TRACK);

    // The kit ships no migrations today, so pumping the migrator is a no-op
    // that consumers can safely call from their own Install migrations.
    expect($migrator->getNewMigrations())->toBe([]);
    expect(fn() => $migrator->up())->not->toThrow(Throwable::class);
});
