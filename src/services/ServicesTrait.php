<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
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
 * Components are declared in [[config()]], which Craft merges into the plugin's
 * Yii config during construction. Each service gets a typed `getX(): X`
 * accessor that narrows Yii's `?object` return for static analysis. The
 * `@property` tags for property-style access live on this trait's docblock —
 * never duplicate them on the main plugin class.
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
     * Returns the component config Craft merges into the plugin's application config.
     *
     * @return array<string, mixed>
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function config(): array
    {
        return [
            'components' => [
                'bus' => ['class' => Bus::class],
                'eventTypes' => ['class' => EventTypes::class],
            ],
        ];
    }

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
}
