<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\audit;

/**
 * AuditEventType is a registered event-type definition — the runtime equivalent
 * of Password Policy's hardcoded `ALLOWED_DETAILS_BY_EVENT` constant. An emitter
 * contributes one per event type it will emit, through
 * {@see \craftpulse\auditkit\events\RegisterAuditEventsEvent}.
 *
 * The definition is the codified privacy + categorisation contract: for every
 * event type an auditor can read exactly which category it lands in, its
 * human-readable label, and precisely which `details` keys a recorder is
 * permitted to persist. Anything outside {@see $allowedDetailKeys} is stripped
 * by {@see \craftpulse\auditkit\services\EventTypes::sanitizeDetails()}; an
 * event type that was never registered is dropped fail-closed at record time.
 *
 * The category is a neutral string rather than an enum: the kit does not own
 * the estate's category vocabulary, so consumers register whatever category
 * strings their own surfaces group on.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
final class AuditEventType
{
    // Public Properties
    // =========================================================================

    /**
     * @var string[] The detail keys a recorder may persist for this event type.
     * Every other key in an {@see AuditEvent}'s `details` is stripped before the
     * row lands.
     *
     * @since 1.0.0
     */
    public readonly array $allowedDetailKeys;

    /**
     * @var string The coarse grouping this event type lands in — authoritative
     * over an {@see AuditEvent}'s declared category.
     *
     * @since 1.0.0
     */
    public readonly string $category;

    /**
     * @var string The event machine-key (e.g. `content.element.saved`).
     *
     * @since 1.0.0
     */
    public readonly string $name;

    /**
     * @var string The human-readable label for CP surfaces.
     *
     * @since 1.0.0
     */
    public readonly string $label;

    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param string $name the event machine-key
     * @param string $category the coarse grouping
     * @param string $label the human-readable label
     * @param string[] $allowedDetailKeys the detail keys a recorder may persist
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function __construct(
        string $name,
        string $category,
        string $label,
        array $allowedDetailKeys = [],
    ) {
        $this->name = $name;
        $this->category = $category;
        $this->label = $label;
        $this->allowedDetailKeys = $allowedDetailKeys;
    }
}
