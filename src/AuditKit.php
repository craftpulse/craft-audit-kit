<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit;

use Craft;
use craft\db\MigrationManager;
use craftpulse\auditkit\integrations\AuthKitBridge;
use craftpulse\auditkit\services\ServicesTrait;
use yii\base\Module;

/**
 * Audit Kit is the free, foundational compliance base for the CraftPulse
 * ecosystem. It owns the neutral audit event contract ({@see audit\AuditEvent}),
 * a runtime event-type registry, a dispatch bus that fans events out to
 * registered sinks, and a byte-deterministic SHA-256 hash-chain engine that
 * consuming plugins (Ledger, Password Policy, Reeve, Loadout) parameterise with
 * their own tables and payloads.
 *
 * Since 1.1.0 Audit Kit is a library-shipped Yii module, not a Craft plugin
 * (the verbb/auth model): it never appears in Craft's installed-plugins list,
 * cannot be enabled or disabled from the CP, and is bootstrapped by consuming
 * plugins calling [[register()]]. Many consumers may (and do) call it on one
 * install; the first call wins and every later call is a cheap no-op that
 * returns the already-registered instance.
 *
 * Like Auth Kit, Audit Kit is primitives + contracts: it ships no tables, no
 * CP UI, no settings, and no editions. The bus is the only piece of state, and
 * it lives in a Yii component so `Event::on` rendezvous works, which is exactly
 * why this is a registered module and not a plain Composer library. A
 * [[getMigrator()|migrator]] on the `module:audit-kit` track stands ready for
 * any future kit-owned migrations (none ship today); consumers pump it from
 * their own Install migrations.
 *
 * Because Audit Kit ships no translations, no CP templates, and no
 * controllers, the module deliberately wires none of the module-manual
 * counterparts (no `PhpMessageSource`, no template roots, no
 * `controllerNamespace`). Add them here if the kit ever grows those surfaces.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class AuditKit extends Module
{
    // Traits
    // =========================================================================

    use ServicesTrait;

    // Const Properties
    // =========================================================================

    /**
     * @var string The module ID Audit Kit registers under on the Craft
     * application. Deliberately equal to the plugin-era handle so log
     * categories, the migration track name, and consumer muscle memory carry
     * over; the plugin-era adoption flow
     * ({@see \craftpulse\auditkit\helpers\PluginAdoption}) removes the old
     * plugin registration, so the ID never collides.
     *
     * @since 1.1.0
     */
    public const ID = 'audit-kit';

    /**
     * @var string The migration track the kit-owned migrator records history
     * under, mirroring Craft's `module:<id>` convention.
     *
     * @since 1.1.0
     */
    public const MIGRATION_TRACK = 'module:' . self::ID;

    // Static Properties
    // =========================================================================

    /**
     * @var AuditKit The module instance. Kept under the plugin-era name so the
     * thirteen consuming plugins' `AuditKit::$plugin->getBus()` call sites
     * survive the 1.1.0 module conversion unchanged. Unset until
     * [[register()]] has run.
     *
     * @since 1.0.0
     */
    public static AuditKit $plugin;

    // Static Methods
    // =========================================================================

    /**
     * Registers Audit Kit as a module on the Craft application and returns the
     * instance.
     *
     * Idempotent by design: every consuming plugin calls this from its own
     * `init()`, and on an install running many CraftPulse plugins the first
     * call constructs and attaches the module while every subsequent call
     * finds it already registered and simply returns it.
     *
     * @return AuditKit the registered module instance
     *
     * @author CraftPulse
     * @since 1.1.0
     */
    public static function register(): AuditKit
    {
        $module = Craft::$app->getModule(self::ID);

        if ($module instanceof self) {
            return $module;
        }

        $module = new self(self::ID);
        self::setInstance($module);
        Craft::$app->setModule(self::ID, $module);

        return $module;
    }

    /**
     * Returns the registered module instance, registering it first if no
     * consumer has done so yet.
     *
     * This is the accessor consumer migrations use
     * (`AuditKit::getInstance()->getMigrator()->up()`): lazy registration
     * means a consumer's Install migration works even when it runs before the
     * consumer's own `init()` wiring.
     *
     * @return AuditKit the registered module instance
     *
     * @author CraftPulse
     * @since 1.1.0
     */
    public static function getInstance(): AuditKit
    {
        return self::register();
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Runs during construction (Yii's `BaseObject::__construct()` calls
     * `init()`), which is BEFORE [[register()]] has attached the instance to
     * the application. Nothing in here may call [[getInstance()]] or
     * [[register()]]: the module is not registered yet, so those would
     * construct a second instance and recurse. Use `$this` directly.
     */
    public function init(): void
    {
        Craft::setAlias('@craftpulse/auditkit', __DIR__);

        parent::init();

        self::$plugin = $this;

        $this->_attachComponents();

        // Kit-owned migrations run on their own module track, pumped by
        // consumers' Install migrations (the verbb/auth idiom).
        $this->set('migrator', [
            'class' => MigrationManager::class,
            'track' => self::MIGRATION_TRACK,
            'migrationNamespace' => 'craftpulse\\auditkit\\migrations',
            'migrationPath' => __DIR__ . DIRECTORY_SEPARATOR . 'migrations',
        ]);

        // The Auth Kit bridge wires a class-level listener with compile-time
        // class strings, so it is safe to attach immediately regardless of
        // whether Auth Kit is installed or of module registration order.
        AuthKitBridge::register();
    }

    /**
     * Returns the kit-owned migration manager on the `module:audit-kit` track.
     *
     * Consumers call `AuditKit::getInstance()->getMigrator()->up()` from their
     * own Install migration's `safeUp()` so any pending kit migrations apply
     * with the first consumer that installs. Never call `down()` from a
     * consumer's `safeDown()`: the kit is shared by every installed consumer,
     * and one consumer's uninstall must not tear down shared kit state.
     *
     * @return MigrationManager
     *
     * @author CraftPulse
     * @since 1.1.0
     */
    public function getMigrator(): MigrationManager
    {
        $component = $this->get('migrator');
        assert($component instanceof MigrationManager);

        return $component;
    }
}
