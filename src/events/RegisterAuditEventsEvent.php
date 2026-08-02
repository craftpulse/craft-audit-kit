<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\events;

use craftpulse\auditkit\audit\AuditEventType;
use yii\base\Event;

/**
 * RegisterAuditEventsEvent collects the {@see AuditEventType} definitions that
 * consuming plugins (and their own capture surfaces) contribute to the kit's
 * event-type registry. An unregistered event type is dropped fail-closed at
 * record time.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class RegisterAuditEventsEvent extends Event
{
    // Public Properties
    // =========================================================================

    /**
     * @var AuditEventType[] The registered event-type definitions.
     *
     * @since 1.0.0
     */
    public array $eventTypes = [];
}
