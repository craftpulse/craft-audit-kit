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

use Craft;
use craftpulse\auditkit\audit\AuditEvent;
use craftpulse\auditkit\audit\AuditSinkInterface;
use craftpulse\auditkit\events\RegisterAuditSinksEvent;
use Throwable;
use yii\base\Component;

/**
 * Bus holds a registry of {@see AuditSinkInterface} implementations and fans an
 * {@see AuditEvent} out to every registered sink.
 *
 * It is the neutral dispatch seam of the compliance foundation, and it mirrors
 * Auth Kit's shipped `Audit` service exactly: an emitter (any plugin, or the
 * Auth Kit bridge) calls [[record()]], and any consuming plugin contributes a
 * sink via the [[EVENT_REGISTER_AUDIT_SINKS]] event or [[setSinks()]]. The sinks
 * are assembled lazily on first use.
 *
 * Recording is synchronous and defensive: each sink is invoked inside its own
 * try/catch, so a failing sink is isolated — it can neither block the emitting
 * flow nor prevent the remaining sinks from running. With no sinks registered
 * [[record()]] is a cheap no-op.
 *
 * The bus performs no allowlist stripping or fail-closed gating — that is the
 * {@see EventTypes} registry's job, applied by a recorder inside its sink. The
 * bus is deliberately dumb and neutral.
 *
 * An instance of the service is available via `AuditKit::$plugin->getBus()`.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class Bus extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @event RegisterAuditSinksEvent The event that is triggered when the sink
     * registry is first assembled, letting consuming plugins contribute their
     * own {@see AuditSinkInterface} implementations.
     * @since 1.0.0
     */
    public const EVENT_REGISTER_AUDIT_SINKS = 'registerAuditSinks';

    // Private Properties
    // =========================================================================

    /**
     * @var AuditSinkInterface[]|null The resolved sinks, lazily assembled from
     * the registration event on first use.
     *
     * @since 1.0.0
     */
    private ?array $_sinks = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the registered audit sinks, assembling them from the registration
     * event on first access.
     *
     * @return AuditSinkInterface[]
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function getSinks(): array
    {
        if ($this->_sinks === null) {
            $event = new RegisterAuditSinksEvent();
            $this->trigger(self::EVENT_REGISTER_AUDIT_SINKS, $event);
            $this->_sinks = $event->sinks;
        }

        return $this->_sinks;
    }

    /**
     * Records an audit event by dispatching it to every registered sink, in
     * registration order. Each sink is wrapped in its own try/catch: a sink that
     * throws is logged and skipped, never blocking the emitting flow nor the
     * sinks after it. With no sinks registered this is a no-op.
     *
     * @param AuditEvent $event the event to record
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function record(AuditEvent $event): void
    {
        foreach ($this->getSinks() as $sink) {
            try {
                $sink->handle($event);
            } catch (Throwable $e) {
                Craft::error(
                    sprintf(
                        'Audit sink %s threw while handling a "%s" event from "%s": %s',
                        $sink::class,
                        $event->name,
                        $event->emitter,
                        $e->getMessage(),
                    ),
                    __METHOD__,
                );
            }
        }
    }

    /**
     * Replaces the registered sinks. Primarily for tests and explicit wiring;
     * runtime registration goes through the event.
     *
     * @param AuditSinkInterface[] $sinks the sinks to register
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function setSinks(array $sinks): void
    {
        $this->_sinks = array_values($sinks);
    }
}
