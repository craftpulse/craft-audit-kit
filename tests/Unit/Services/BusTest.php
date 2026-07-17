<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Tests for the dispatch bus: the zero-sink no-op default, lazy assembly from
 * the registration event, ordered fan-out through record(), and the guarantee
 * that a throwing sink is isolated — it neither bubbles nor blocks the sinks
 * after it. Also covers the AuditEvent value class's frozen shape.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\auditkit\audit\AuditEvent;
use craftpulse\auditkit\audit\AuditSinkInterface;
use craftpulse\auditkit\events\RegisterAuditSinksEvent;
use craftpulse\auditkit\services\Bus;

/**
 * Returns a sink that appends the event names it handled to the given log array
 * (by reference), or throws if $throw is set.
 */
function busRecordingSink(array &$log, ?string $throw = null): AuditSinkInterface
{
    return new class($log, $throw) implements AuditSinkInterface {
        /** @param string[] $log */
        public function __construct(private array &$log, private ?string $throw)
        {
        }

        public function handle(AuditEvent $event): void
        {
            $this->log[] = $event->name;

            if ($this->throw !== null) {
                throw new RuntimeException($this->throw);
            }
        }
    };
}

it('records nothing and does not error when no sinks are registered', function() {
    $bus = new Bus();
    $bus->setSinks([]);

    $bus->record(new AuditEvent('login.sso', 'auth', 'warden'));

    expect($bus->getSinks())->toBe([]);
});

it('dispatches an event to every registered sink, in registration order', function() {
    $log = [];
    $bus = new Bus();
    $bus->setSinks([busRecordingSink($log), busRecordingSink($log)]);

    $bus->record(new AuditEvent('login.passkey', 'auth', 'warp'));

    expect($log)->toBe(['login.passkey', 'login.passkey']);
});

it('isolates a throwing sink: it neither bubbles nor blocks the sinks after it', function() {
    $log = [];
    $bus = new Bus();
    $bus->setSinks([
        busRecordingSink($log),
        busRecordingSink($log, throw: 'sink exploded'),
        busRecordingSink($log),
    ]);

    $bus->record(new AuditEvent('session.revoked', 'auth', 'warden', details: ['scope' => 'backchannel']));

    expect($log)->toBe(['session.revoked', 'session.revoked', 'session.revoked']);
});

it('assembles sinks from the registration event on first use', function() {
    $log = [];
    $bus = new Bus();

    $bus->on(Bus::EVENT_REGISTER_AUDIT_SINKS, function(RegisterAuditSinksEvent $event) use (&$log): void {
        $event->sinks[] = busRecordingSink($log);
    });

    $bus->record(new AuditEvent('passkey.enrolled', 'auth', 'warp'));

    expect($log)->toBe(['passkey.enrolled']);
});

it('rejects an outcome that is not one of the three outcome constants', function() {
    expect(fn() => new AuditEvent('login.otp', 'auth', 'warp', outcome: 'partial'))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts success, failure, and warning outcomes', function() {
    $success = new AuditEvent('login.magic_link', 'auth', 'warp', outcome: AuditEvent::OUTCOME_SUCCESS);
    $failure = new AuditEvent('login.magic_link', 'auth', 'warp', outcome: AuditEvent::OUTCOME_FAILURE);
    $warning = new AuditEvent('account.locked', 'auth', 'password-policy', outcome: AuditEvent::OUTCOME_WARNING);

    expect($success->outcome)->toBe('success')
        ->and($failure->outcome)->toBe('failure')
        ->and($warning->outcome)->toBe('warning');
});

it('rejects non-scalar details but allows null', function() {
    expect(fn() => new AuditEvent('login.sso', 'auth', 'warden', details: ['provider' => ['nested' => 'x']]))
        ->toThrow(InvalidArgumentException::class);

    $event = new AuditEvent('login.sso', 'auth', 'warden', details: ['provider' => null, 'count' => 3]);

    expect($event->details)->toBe(['provider' => null, 'count' => 3]);
});

it('collapses an auth subject onto the neutral target pair via the constructor', function() {
    $event = new AuditEvent(
        name: 'login.otp',
        category: 'auth',
        emitter: 'warp',
        actorId: 7,
        targetType: 'user',
        targetId: 42,
    );

    expect($event)->not->toBeInstanceOf(yii\base\Model::class)
        ->and($event->targetId)->toBe(42)
        ->and($event->targetType)->toBe('user')
        ->and($event->actorId)->toBe(7);

    expect(fn() => $event->outcome = AuditEvent::OUTCOME_FAILURE)->toThrow(Error::class);
});
