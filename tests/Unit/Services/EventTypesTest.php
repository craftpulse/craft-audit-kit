<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Tests for the event-type registry: lazy assembly from the registration
 * event, fail-closed lookup of an unregistered type, and the allowlist strip
 * applied by sanitizeDetails().
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\auditkit\audit\AuditEventType;
use craftpulse\auditkit\events\RegisterAuditEventsEvent;
use craftpulse\auditkit\services\EventTypes;

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
