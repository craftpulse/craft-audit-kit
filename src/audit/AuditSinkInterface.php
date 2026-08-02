<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\audit;

/**
 * AuditSinkInterface is the cooperation seam for audit persistence and
 * forwarding. An emitter records an {@see AuditEvent} through the
 * {@see \craftpulse\auditkit\services\Bus}; any consuming plugin (Ledger, and
 * future recorders) ships a thin adapter implementing this interface and
 * registers it via
 * {@see \craftpulse\auditkit\services\Bus::EVENT_REGISTER_AUDIT_SINKS}. With no
 * sink registered the bus is a graceful no-op — recording is cheap and does
 * nothing.
 *
 * The contract is deliberately tiny and neutral. Two rules bind every sink:
 *
 * - A sink must ignore {@see AuditEvent} names and categories it does not
 *   recognize, silently. The kit's vocabulary grows in minor releases, so a
 *   sink may receive a name newer than the vocabulary it was written against.
 * - A sink's [[handle()]] must never assume it can block the emitter: the bus
 *   wraps each sink in its own try/catch, so a throwing sink is isolated and
 *   never derails the emitting flow — but a sink should still fail softly (log
 *   and return) rather than rely on that backstop.
 *
 * Treat any change to this interface as a major version bump.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
interface AuditSinkInterface
{
    // Public Methods
    // =========================================================================

    /**
     * Handles one recorded audit event, persisting or forwarding it as the sink
     * sees fit. Unknown {@see AuditEvent::$name} values must be ignored
     * silently.
     *
     * @param AuditEvent $event the event to handle
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function handle(AuditEvent $event): void;
}
