<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Tests for the event-type registry: lazy assembly from the registration
 * event, fail-closed lookup of an unregistered type, and the allowlist strip
 * applied by sanitizeDetails().
 *
 * Each test below constructs its own {@see EventTypes} instance and drives
 * the registration event through the instance-level
 * {@see \yii\base\Component::on()} API. {@see \yii\base\Component::trigger()}
 * also invokes any *class-level* handlers attached via
 * {@see \yii\base\Event::on()} for {@see EventTypes} — which is exactly how
 * consuming plugins (Ledger, Reeve, Loadout) contribute their own event
 * types at runtime. When one of those plugins happens to be installed in the
 * same Craft install this suite runs against, its class-level handler would
 * otherwise fire alongside the test's own handler and pollute the registry
 * under test with events this suite never registered. {@see
 * EventTypesClassHandlerGuard} detaches any such class-level handlers for the
 * duration of this file and restores them once it finishes, so the suite is
 * isolated from whatever plugins happen to be installed without touching
 * runtime registration behaviour at all.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\auditkit\audit\AuditEventType;
use craftpulse\auditkit\events\RegisterAuditEventsEvent;
use craftpulse\auditkit\services\EventTypes;
use yii\base\Event;

/**
 * Detaches and restores the class-level handlers registered on
 * {@see EventTypes::EVENT_REGISTER_AUDIT_EVENTS} for the duration of this
 * test file, so the registry-assembly tests below only ever see the
 * instance-level handler they attach themselves. Consuming plugins register
 * on this event through {@see \yii\base\Event::on()} (a *class*-level, static
 * registration that fires for every {@see EventTypes} instance), so simply
 * constructing a fresh instance per test is not enough isolation on its own.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
final class EventTypesClassHandlerGuard
{
    // Private Static Properties
    // =========================================================================

    /**
     * @var array The class-level handlers captured by {@see self::detach()},
     * to be replayed by {@see self::restore()}.
     *
     * @since 1.0.0
     */
    private static array $_handlers = [];

    // Public Static Methods
    // =========================================================================

    /**
     * Captures whatever class-level handlers are currently registered for
     * {@see EventTypes::EVENT_REGISTER_AUDIT_EVENTS} and detaches them, so
     * this suite's own registry-assembly tests run against a clean registry
     * regardless of which plugins happen to be installed.
     *
     * @return void
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function detach(): void
    {
        $property = new ReflectionProperty(Event::class, '_events');
        $property->setAccessible(true);

        /** @var array $events */
        $events = $property->getValue();

        self::$_handlers = $events[EventTypes::EVENT_REGISTER_AUDIT_EVENTS][EventTypes::class] ?? [];

        Event::off(EventTypes::class, EventTypes::EVENT_REGISTER_AUDIT_EVENTS);
    }

    /**
     * Replays the class-level handlers captured by {@see self::detach()},
     * restoring the registry to the state runtime code left it in before
     * this suite ran.
     *
     * @return void
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function restore(): void
    {
        if (self::$_handlers === []) {
            return;
        }

        $property = new ReflectionProperty(Event::class, '_events');
        $property->setAccessible(true);

        /** @var array $events */
        $events = $property->getValue();
        $events[EventTypes::EVENT_REGISTER_AUDIT_EVENTS][EventTypes::class] = self::$_handlers;

        $property->setValue(null, $events);

        self::$_handlers = [];
    }
}

beforeAll(fn() => EventTypesClassHandlerGuard::detach());
afterAll(fn() => EventTypesClassHandlerGuard::restore());

it('returns null for an unregistered event type (fail-closed lookup)', function() {
    $registry = new EventTypes();
    $registry->setEventTypes([]);

    expect($registry->getEventType('never.registered'))->toBeNull();
});

it('assembles the registry from the registration event on first use', function() {
    $registry = new EventTypes();

    $registry->on(EventTypes::EVENT_REGISTER_AUDIT_EVENTS, function(RegisterAuditEventsEvent $event): void {
        $event->eventTypes[] = new AuditEventType('content.element.saved', 'content', 'Element saved', ['sectionId']);
    });

    $type = $registry->getEventType('content.element.saved');

    expect($type)->toBeInstanceOf(AuditEventType::class)
        ->and($type->category)->toBe('content')
        ->and($type->allowedDetailKeys)->toBe(['sectionId']);
});

it('strips detail keys the event type does not allow', function() {
    $registry = new EventTypes();
    $type = new AuditEventType('content.element.saved', 'content', 'Element saved', ['sectionId']);

    $sanitized = $registry->sanitizeDetails($type, [
        'sectionId' => 5,
        'secretLeak' => 'should be stripped',
    ]);

    expect($sanitized)->toBe(['sectionId' => 5]);
});

it('returns null from sanitizeDetails when nothing survives the allowlist', function() {
    $registry = new EventTypes();
    $type = new AuditEventType('content.element.saved', 'content', 'Element saved', ['sectionId']);

    expect($registry->sanitizeDetails($type, ['nothing' => 'allowed']))->toBeNull();
});
