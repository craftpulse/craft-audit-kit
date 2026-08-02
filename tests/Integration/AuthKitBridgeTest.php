<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Integration coverage for the Auth Kit bridge: with Auth Kit present, firing
 * an AuthEvent through Auth Kit's `Audit` service must land a mapped AuditEvent
 * on a sink registered on the kit bus. This exercises the full seam — Auth Kit
 * 1.5.0's `EVENT_AFTER_RECORD`, the bridge's class-level listener registered at
 * plugin boot, the AuthEvent to AuditEvent mapping, and the bus fan-out.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\auditkit\audit\AuditEvent;
use craftpulse\auditkit\audit\AuditSinkInterface;
use craftpulse\auditkit\AuditKit;
use craftpulse\auditkit\integrations\AuthKitBridge;
use craftpulse\authkit\audit\AuthEvent;
use craftpulse\authkit\services\Audit;

/**
 * Returns a sink that captures every AuditEvent it handles into the given array
 * (by reference).
 *
 * @param AuditEvent[] $captured
 */
function capturingBusSink(array &$captured): AuditSinkInterface
{
    return new class($captured) implements AuditSinkInterface {
        /** @param AuditEvent[] $captured */
        public function __construct(private array &$captured)
        {
        }

        public function handle(AuditEvent $event): void
        {
            $this->captured[] = $event;
        }
    };
}

it('relays an Auth Kit event onto the bus as a mapped AuditEvent', function() {
    $captured = [];
    AuditKit::$plugin->getBus()->setSinks([capturingBusSink($captured)]);

    // Fire through Auth Kit's Audit — the bridge's class-level listener on
    // EVENT_AFTER_RECORD is wired at plugin boot and relays onto the bus.
    $authService = new Audit();
    $authService->setSinks([]);
    $authService->record(new AuthEvent(
        name: AuthEvent::LOGIN_MAGIC_LINK,
        emitter: 'warp',
        outcome: AuthEvent::OUTCOME_SUCCESS,
        userId: 42,
        details: ['provider' => 'internal'],
        actorId: 7,
    ));

    expect($captured)->toHaveCount(1);

    $event = $captured[0];
    expect($event)->toBeInstanceOf(AuditEvent::class)
        ->and($event->name)->toBe(AuthEvent::LOGIN_MAGIC_LINK)
        ->and($event->category)->toBe(AuthKitBridge::CATEGORY_AUTH)
        ->and($event->emitter)->toBe('warp')
        ->and($event->outcome)->toBe(AuditEvent::OUTCOME_SUCCESS)
        ->and($event->actorId)->toBe(7)
        ->and($event->targetType)->toBe(AuthKitBridge::TARGET_TYPE_USER)
        ->and($event->targetId)->toBe(42)
        ->and($event->details)->toBe(['provider' => 'internal']);
});

it('maps a null userId onto a null target', function() {
    $captured = [];
    AuditKit::$plugin->getBus()->setSinks([capturingBusSink($captured)]);

    AuthKitBridge::relay(new AuthEvent(
        name: AuthEvent::SESSION_REVOKED,
        emitter: 'warden',
        outcome: AuthEvent::OUTCOME_FAILURE,
        details: ['scope' => 'backchannel'],
    ));

    expect($captured)->toHaveCount(1)
        ->and($captured[0]->targetType)->toBeNull()
        ->and($captured[0]->targetId)->toBeNull()
        ->and($captured[0]->outcome)->toBe(AuditEvent::OUTCOME_FAILURE);
});

it('drops events whose name is outside the frozen AuthEvent vocabulary', function() {
    $captured = [];
    AuditKit::$plugin->getBus()->setSinks([capturingBusSink($captured)]);
    AuthKitBridge::resetVocabulary();

    // A foreign domain riding the AuthEvent contract (the Savepoint pattern):
    // AuthEvent does not validate names, so this constructs fine — but the
    // bridge, as a contract consumer, must ignore the unknown name rather than
    // stamp a non-auth event into recorders' chains as `category: auth`.
    AuthKitBridge::relay(new AuthEvent(
        name: 'savepoint.restore_applied',
        emitter: 'savepoint',
        outcome: AuthEvent::OUTCOME_SUCCESS,
        userId: 42,
        details: ['savepointId' => 7],
    ));

    expect($captured)->toBeEmpty();
});

it('relays every name in the frozen vocabulary and nothing else via reflection', function() {
    $captured = [];
    AuditKit::$plugin->getBus()->setSinks([capturingBusSink($captured)]);
    AuthKitBridge::resetVocabulary();

    $reflection = new ReflectionClass(AuthEvent::class);
    $names = [];
    foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
        $value = $constant->getValue();
        if (is_string($value) && !str_starts_with($constant->getName(), 'OUTCOME_')) {
            $names[] = $value;
        }
    }

    expect($names)->not->toBeEmpty();

    foreach ($names as $name) {
        AuthKitBridge::relay(new AuthEvent(
            name: $name,
            emitter: 'test',
            outcome: AuthEvent::OUTCOME_SUCCESS,
        ));
    }

    // Outcome values are string constants too — they must not be in the vocabulary.
    AuthKitBridge::relay(new AuthEvent(
        name: AuthEvent::OUTCOME_SUCCESS,
        emitter: 'test',
        outcome: AuthEvent::OUTCOME_SUCCESS,
    ));

    expect($captured)->toHaveCount(count($names));
});
