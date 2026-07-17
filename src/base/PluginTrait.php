<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\base;

use craftpulse\auditkit\integrations\AuthKitBridge;

/**
 * PluginTrait owns Audit Kit's event listeners and plugin lifecycle wiring,
 * keeping the main plugin class a thin orchestrator.
 *
 * Audit Kit imposes no URL rules, ships no controllers, and owns no tables.
 * The only wiring it performs is the Auth Kit bridge: when Auth Kit is present,
 * its authentication events are mapped onto the audit bus so recorders wire a
 * single seam. The bridge is a no-op when Auth Kit is absent.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
trait PluginTrait
{
    // Private Methods
    // =========================================================================

    /**
     * Attaches Audit Kit's event handlers.
     *
     * Called from `AuditKit::init()` once the application has fully initialized.
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private function _attachEventHandlers(): void
    {
        AuthKitBridge::register();
    }
}
