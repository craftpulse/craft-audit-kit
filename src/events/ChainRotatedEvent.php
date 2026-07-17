<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\events;

use DateTime;
use yii\base\Event;

/**
 * ChainRotatedEvent fires after {@see \craftpulse\auditkit\engine\Pruner::prune()}
 * deletes one or more rows from a chain table. The chain start moves forward —
 * the new first row's `previousHash` legitimately references a now-deleted row,
 * which the verifier treats as a chain-start sentinel within the retention
 * window rather than a tamper.
 *
 * Listeners use it to record the retention boundary externally (a SIEM
 * forwarder, an off-site archive, a compliance dashboard) and to anchor the
 * verifier's next chain-walk at the new first surviving row. Skipped entirely
 * when the prune deleted zero rows or emptied the table.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class ChainRotatedEvent extends Event
{
    // Public Properties
    // =========================================================================

    /**
     * @var int The highest `id` deleted in this prune. Pair with `endRowHash`
     * to anchor the last row that left the database.
     *
     * @since 1.0.0
     */
    public int $endId;

    /**
     * @var string The `previousHash` of the new first surviving row —
     * equivalently, the `rowHash` of the highest deleted row (`endId`). This IS
     * the rotation boundary. Not the surviving head's own `rowHash` (that is
     * `startRowHash`); the two are never equal under the id-boundary prune.
     *
     * @since 1.0.0
     */
    public string $endRowHash;

    /**
     * @var DateTime The timestamp at which the rotation occurred (server UTC).
     *
     * @since 1.0.0
     */
    public DateTime $rotatedAt;

    /**
     * @var int The `id` of the new first surviving row — the new chain start.
     *
     * @since 1.0.0
     */
    public int $startId;

    /**
     * @var string The `rowHash` of the new first surviving row. Pair with
     * `startId` for the verifier-facing anchor.
     *
     * @since 1.0.0
     */
    public string $startRowHash;
}
