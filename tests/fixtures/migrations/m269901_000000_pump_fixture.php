<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * A stand-in kit migration for the adoption helper's migrator pump. Audit Kit
 * ships no migrations of its own, so the pump added in 1.1.2 has nothing to
 * apply on any real install and cannot be observed there. This file is a real,
 * discoverable migration on the `module:audit-kit` track that the pump test
 * points the migrator at, so the seam is asserted by watching a migration
 * actually run rather than by mocking the migrator.
 *
 * It lives under `tests/`, not `src/migrations/`, precisely so it is never
 * discovered on a consumer's install. It performs no schema work: the suite
 * runs inside a transaction craft-pest rolls back, and DDL would commit it out
 * from under the rest of the test.
 *
 * The class namespace is the kit's real migration namespace because
 * [[\craft\db\MigrationManager::createMigration()]] resolves a migration class
 * as `migrationNamespace . '\\' . $name` after requiring the file it found in
 * `migrationPath`. The Composer autoloader never maps this file, and never
 * needs to.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\migrations;

use craft\db\Migration;

/**
 * m269901_000000_pump_fixture migration.
 *
 * @author CraftPulse
 * @since 1.1.2
 */
class m269901_000000_pump_fixture extends Migration
{
    // Static Properties
    // =========================================================================

    /**
     * @var int How many times [[safeUp()]] has run in this process. Process
     * state rather than database state, so it survives the transaction rollback
     * between tests; every test that reads it resets it first.
     *
     * @since 1.1.2
     */
    public static int $applied = 0;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        self::$applied++;

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        return false;
    }
}
