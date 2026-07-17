<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit;

use Craft;
use craft\base\Plugin;
use craftpulse\auditkit\base\PluginTrait;
use craftpulse\auditkit\services\ServicesTrait;

/**
 * Audit Kit is the free, foundational compliance base for the CraftPulse
 * ecosystem. It owns the neutral audit event contract ({@see audit\AuditEvent}),
 * a runtime event-type registry, a dispatch bus that fans events out to
 * registered sinks, and a byte-deterministic SHA-256 hash-chain engine that
 * consuming plugins (Ledger, Password Policy, Reeve, Loadout) parameterise with
 * their own tables and payloads.
 *
 * Like Auth Kit, Audit Kit is primitives + contracts: it ships no tables, no
 * migrations, no CP UI, no settings, and no editions. The bus is the only piece
 * of state, and it lives in a Yii component so `Event::on` rendezvous works —
 * exactly why this is a plugin and not a plain Composer library. Consuming
 * plugins `require` the base and call into its services.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class AuditKit extends Plugin
{
    // Traits
    // =========================================================================

    use PluginTrait;
    use ServicesTrait;

    // Static Properties
    // =========================================================================

    /**
     * @var AuditKit The plugin instance.
     *
     * @since 1.0.0
     */
    public static AuditKit $plugin;

    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether the plugin has its own section in the control panel.
     *
     * @since 1.0.0
     */
    public bool $hasCpSection = false;

    /**
     * @var bool Whether the plugin has a settings page in the control panel.
     *
     * @since 1.0.0
     */
    public bool $hasCpSettings = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        Craft::setAlias('@craftpulse/auditkit', __DIR__);

        Craft::$app->onInit(function() {
            $this->_attachEventHandlers();
        });
    }
}
