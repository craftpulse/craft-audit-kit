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

use InvalidArgumentException;

/**
 * AuditEvent is the neutral, immutable value object an emitter (any CraftPulse
 * plugin, the Auth Kit bridge, or a plugin's own capture surface) hands the
 * audit [[\craftpulse\auditkit\services\Bus]] to describe one auditable fact. A
 * sink (a recorder such as Ledger) receives it and decides what to persist.
 *
 * It supersets Auth Kit's frozen `AuthEvent`: `AuthEvent`'s `userId` collapses
 * into the neutral `targetType`/`targetId` pair (an auth subject is a target of
 * type `user`), and `AuthEvent`'s `emitter` maps straight across. The generalised
 * shape is Ledger's already-shipped `LedgerEvent`, moved to the kit with two
 * field renames: `eventType` becomes [[$name]], `source` becomes [[$emitter]].
 *
 * Like `AuthEvent` this is a plain final value class rather than a
 * `craft\base\Model`, and its shape is frozen at 1.0.0: the readonly properties
 * and the constructor signature are the contract. Treat any change to that shape
 * as a major version bump. New outcome or category vocabulary is additive —
 * ships in minors — and recorders ignore names and categories they don't
 * recognise.
 *
 * Contract rules the value object enforces and emitters must honor:
 *
 * - [[$outcome]] must be one of [[OUTCOME_SUCCESS]], [[OUTCOME_FAILURE]], or
 *   [[OUTCOME_WARNING]]; anything else throws.
 * - [[$details]] must be scalar-only (or `null`) and carry no PII — no emails,
 *   raw IPs, or raw user agents. A non-scalar, non-null value throws. A
 *   downstream event type's allowlist may strip unknown keys, but the neutral
 *   contract keeps the payload clean at the source.
 * - [[$name]] is deliberately not validated against a known set: forward
 *   compatibility requires that a newer emitter's name survive an older kit,
 *   and recorders tolerate unknown names by design.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
final class AuditEvent
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The event failed.
     *
     * @since 1.0.0
     */
    public const OUTCOME_FAILURE = 'failure';

    /**
     * @var string The event succeeded.
     *
     * @since 1.0.0
     */
    public const OUTCOME_SUCCESS = 'success';

    /**
     * @var string The event is a non-fatal warning (e.g. an account lockout).
     *
     * @since 1.0.0
     */
    public const OUTCOME_WARNING = 'warning';

    // Public Properties
    // =========================================================================

    /**
     * @var int|null The acting user, or null for a system/self event.
     *
     * @since 1.0.0
     */
    public readonly ?int $actorId;

    /**
     * @var string The coarse grouping (e.g. `auth`, `content`, `system`,
     * `permissions`, `plugins`, `editorial`, `estate`). Additive vocabulary —
     * recorders ignore categories they don't recognise.
     *
     * @since 1.0.0
     */
    public readonly string $category;

    /**
     * @var array<string, scalar|null> Scalar-only, non-PII context.
     *
     * @since 1.0.0
     */
    public readonly array $details;

    /**
     * @var array<string, mixed>|null Before to after change diff, or null.
     *
     * @since 1.0.0
     */
    public readonly ?array $diff;

    /**
     * @var string The emitting plugin's handle (e.g. `warden`, `ledger`).
     *
     * @since 1.0.0
     */
    public readonly string $emitter;

    /**
     * @var int|null The site the audited action applied to.
     *
     * @since 1.0.0
     */
    public readonly ?int $eventSiteId;

    /**
     * @var string The event machine-key (e.g. `login.magic_link`,
     * `content.element.saved`).
     *
     * @since 1.0.0
     */
    public readonly string $name;

    /**
     * @var string The outcome — one of the `OUTCOME_*` constants.
     *
     * @since 1.0.0
     */
    public readonly string $outcome;

    /**
     * @var int|null The id of the element/thing acted upon.
     *
     * @since 1.0.0
     */
    public readonly ?int $targetId;

    /**
     * @var string|null The type/handle of the thing acted upon (an auth subject
     * is `user`).
     *
     * @since 1.0.0
     */
    public readonly ?string $targetType;

    /**
     * @var string|null The uid of the element acted upon.
     *
     * @since 1.0.0
     */
    public readonly ?string $targetUid;

    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param string $name the registered event machine-key
     * @param string $category the coarse grouping
     * @param string $emitter the emitting plugin's handle
     * @param string $outcome one of the `OUTCOME_*` constants
     * @param int|null $actorId the acting user, or null
     * @param string|null $targetType the type/handle of the thing acted upon
     * @param int|null $targetId the id of the thing acted upon
     * @param string|null $targetUid the uid of the element acted upon
     * @param array<string, scalar|null> $details scalar-only, non-PII context
     * @param array<string, mixed>|null $diff before to after change diff
     * @param int|null $eventSiteId the site the audited action applied to
     * @throws InvalidArgumentException if $outcome is unknown or a $details
     * value is not scalar or null
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function __construct(
        string $name,
        string $category,
        string $emitter,
        string $outcome = self::OUTCOME_SUCCESS,
        ?int $actorId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $targetUid = null,
        array $details = [],
        ?array $diff = null,
        ?int $eventSiteId = null,
    ) {
        if (!in_array($outcome, [self::OUTCOME_SUCCESS, self::OUTCOME_FAILURE, self::OUTCOME_WARNING], true)) {
            throw new InvalidArgumentException(sprintf(
                'AuditEvent outcome must be "%s", "%s", or "%s", "%s" given.',
                self::OUTCOME_SUCCESS,
                self::OUTCOME_FAILURE,
                self::OUTCOME_WARNING,
                $outcome,
            ));
        }

        foreach ($details as $key => $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException(sprintf(
                    'AuditEvent details must be scalar-only; the "%s" value is %s.',
                    $key,
                    get_debug_type($value),
                ));
            }
        }

        $this->name = $name;
        $this->category = $category;
        $this->emitter = $emitter;
        $this->outcome = $outcome;
        $this->actorId = $actorId;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->targetUid = $targetUid;
        $this->details = $details;
        $this->diff = $diff;
        $this->eventSiteId = $eventSiteId;
    }
}
