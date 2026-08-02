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

use craftpulse\auditkit\audit\AuditEventType;
use craftpulse\auditkit\events\RegisterAuditEventsEvent;
use yii\base\Component;

/**
 * EventTypes is the runtime event-type registry — the generalisation of
 * Password Policy's hardcoded `ALLOWED_DETAILS_BY_EVENT` constant into a
 * registration-event-driven component.
 *
 * A consuming plugin contributes one {@see AuditEventType} per event type it
 * emits, through the [[EVENT_REGISTER_AUDIT_EVENTS]] event. A recorder's sink
 * then consults the registry before persisting: an unregistered event type is
 * dropped fail-closed (a typo'd or unregistered name must never write an
 * unconstrained payload), and a registered type's allowlist strips any `details`
 * key it doesn't permit.
 *
 * The registry is assembled lazily on first use. Password Policy keeps its own
 * const allowlist (a closed set, no migration) and does not use this component;
 * it is here for the runtime-registry consumers (Ledger, Reeve, Loadout).
 *
 * An instance of the service is available via `AuditKit::$plugin->getEventTypes()`.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class EventTypes extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @event RegisterAuditEventsEvent The event triggered when the registry is
     * first assembled, letting consuming plugins contribute
     * {@see AuditEventType} definitions.
     * @since 1.0.0
     */
    public const EVENT_REGISTER_AUDIT_EVENTS = 'registerAuditEvents';

    // Private Properties
    // =========================================================================

    /**
     * @var array<string, AuditEventType>|null The resolved registry, keyed by
     * event name, lazily assembled from the registration event on first use.
     *
     * @since 1.0.0
     */
    private ?array $_eventTypes = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the registered event type for a machine-key, or null when it was
     * never registered.
     *
     * @param string $name
     * @return AuditEventType|null
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getEventType(string $name): ?AuditEventType
    {
        return $this->getEventTypes()[$name] ?? null;
    }

    /**
     * Returns the registry — every registered {@see AuditEventType} keyed by
     * event name — assembling it from the registration event on first access.
     *
     * @return array<string, AuditEventType>
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getEventTypes(): array
    {
        if ($this->_eventTypes === null) {
            $event = new RegisterAuditEventsEvent();
            $this->trigger(self::EVENT_REGISTER_AUDIT_EVENTS, $event);

            $registry = [];
            foreach ($event->eventTypes as $type) {
                $registry[$type->name] = $type;
            }

            $this->_eventTypes = $registry;
        }

        return $this->_eventTypes;
    }

    /**
     * Returns the subset of a `details` array whose keys the given event type
     * permits, or null when nothing survives. Keys not on the type's allowlist
     * are stripped — the fail-closed privacy contract applied at record time.
     *
     * @param AuditEventType $type
     * @param array<string, scalar|null> $details
     * @return array<string, scalar|null>|null
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function sanitizeDetails(AuditEventType $type, array $details): ?array
    {
        $filtered = array_intersect_key($details, array_flip($type->allowedDetailKeys));

        return $filtered === [] ? null : $filtered;
    }

    /**
     * Replaces the registered event types. Primarily for tests and explicit
     * wiring; runtime registration goes through the event.
     *
     * @param AuditEventType[] $eventTypes the event types to register
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function setEventTypes(array $eventTypes): void
    {
        $registry = [];
        foreach ($eventTypes as $type) {
            $registry[$type->name] = $type;
        }

        $this->_eventTypes = $registry;
    }
}
