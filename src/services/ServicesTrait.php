<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\services;

/**
 * ServicesTrait owns Audit Kit's service component registration and typed
 * accessors.
 *
 * Components are attached in [[_attachComponents()]], which the module calls
 * from `AuditKit::init()` (a library-shipped module has no Craft plugin
 * config-merge, so the module wires its own service locator). Each service
 * gets a typed `getX(): X` accessor that narrows Yii's `?object` return for
 * static analysis. The `@property` tags for property-style access live on
 * this trait's docblock — never duplicate them on the main module class.
 *
 * Only the two stateful runtime registries live here as components — the
 * dispatch [[Bus]] and the [[EventTypes]] registry. The stateless engine
 * classes ([[\craftpulse\auditkit\engine\Canonicalizer]],
 * [[\craftpulse\auditkit\engine\ChainWriter]], and friends) are plain classes
 * consuming plugins instantiate directly with their own table + payload +
 * storage closure.
 *
 * @property-read Bus $bus
 * @property-read EventTypes $eventTypes
 *
 * @author CraftPulse
 * @since 1.0.0
 */
trait ServicesTrait
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the dispatch bus.
     *
     * @return Bus
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getBus(): Bus
    {
        $component = $this->get('bus');
        assert($component instanceof Bus);

        return $component;
    }

    /**
     * Returns the event-type registry.
     *
     * @return EventTypes
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getEventTypes(): EventTypes
    {
        $component = $this->get('eventTypes');
        assert($component instanceof EventTypes);

        return $component;
    }

    // Private Methods
    // =========================================================================

    /**
     * Attaches Audit Kit's service components to the module's service locator.
     *
     * Called once from `AuditKit::init()`.
     *
     * @author CraftPulse
     * @since 1.1.0
     */
    private function _attachComponents(): void
    {
        $this->setComponents([
            'bus' => ['class' => Bus::class],
            'eventTypes' => ['class' => EventTypes::class],
        ]);
    }
}
