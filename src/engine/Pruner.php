<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\engine;

use Carbon\Carbon;
use craft\db\Query;
use craftpulse\auditkit\events\ChainRotatedEvent;
use yii\base\Component;
use yii\db\Connection;

/**
 * Pruner deletes chain rows past a retention boundary in a way that keeps the
 * surviving rows a clean chain suffix. It is the extraction of Password Policy's
 * `purgeOldEntries()`: the retention predicate is collapsed to a single `maxId`
 * boundary so a contiguous id prefix is always removed — never a mid-chain row.
 *
 * Why the id boundary, not `dateCreated`
 * --------------------------------------
 * The chain links rows by `id` order; `previousHash` references the `rowHash` of
 * the immediately-lower `id`. A wall-clock step (NTP correction, DST jump, an
 * out-of-order backfill) can make one row's `dateCreated` older than a lower-id
 * neighbour's. Deleting by `dateCreated < threshold` would punch a mid-chain
 * hole the verifier reports as tampering. Resolving the highest id past the
 * threshold and deleting `id <= maxId` guarantees a clean prefix removal.
 *
 * Deletion mechanism is the consumer's
 * ------------------------------------
 * Element-backed chains (Password Policy, Ledger) must delete the paired
 * `craft_elements` rows so the FK CASCADE drops the audit rows; a plain-record
 * chain deletes its own table. The Pruner therefore hands the resolved id list
 * to a consumer-supplied `$deleteUpToId` closure and lets it own the delete. On
 * a successful prune (deleted >= 1 row AND >= 1 row survives) it fires
 * {@see ChainRotatedEvent} with the boundary payload; a no-op prune fires
 * nothing.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class Pruner extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @event ChainRotatedEvent Fired after a prune deletes one or more rows and
     * at least one row survives. Skipped when the prune deleted zero rows or
     * emptied the table.
     * @since 1.0.0
     */
    public const EVENT_CHAIN_ROTATED = 'chainRotated';

    // Public Methods
    // =========================================================================

    /**
     * Prunes rows older than the retention window and returns the number
     * deleted.
     *
     * @param Connection $db the database connection
     * @param string $table the chain table, in `{{%handle}}` form
     * @param int $daysToKeep the retention window in days
     * @param callable(int[]): int $deleteUpToId closure that deletes the given
     * id list (owning element-cascade vs plain-record semantics) and returns the
     * deleted count
     * @return int the number of rows deleted
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function prune(Connection $db, string $table, int $daysToKeep, callable $deleteUpToId): int
    {
        $threshold = Carbon::now('UTC')->subDays($daysToKeep)->format('Y-m-d H:i:s');

        // Resolve the highest id past the retention threshold, then delete by
        // id boundary — never by dateCreated directly (see the class docblock).
        $maxId = (new Query())
            ->from($table)
            ->where(['<', 'dateCreated', $threshold])
            ->max('id', $db);

        if ($maxId === null) {
            return 0;
        }

        $maxId = (int)$maxId;

        /** @var int[] $expiredIds */
        $expiredIds = (new Query())
            ->select(['id'])
            ->from($table)
            ->where(['<=', 'id', $maxId])
            ->column($db);

        if ($expiredIds === []) {
            return 0;
        }

        $deleted = $deleteUpToId(array_map('intval', $expiredIds));

        if ($deleted < 1) {
            return $deleted;
        }

        // Resolve the new chain head (first surviving row). When the prune
        // emptied the table there is no anchor for listeners — skip the event
        // so consumers never handle a "rotation with no head" payload.
        // `previousHash` IS the rotation boundary: the surviving head's
        // `previousHash` is the `rowHash` of the highest deleted row.
        $startRow = (new Query())
            ->select(['id', 'rowHash', 'previousHash'])
            ->from($table)
            ->orderBy(['id' => SORT_ASC])
            ->limit(1)
            ->one($db);

        if (!is_array($startRow)) {
            return $deleted;
        }

        $this->trigger(
            self::EVENT_CHAIN_ROTATED,
            new ChainRotatedEvent([
                'startId' => (int)$startRow['id'],
                'startRowHash' => (string)$startRow['rowHash'],
                'endId' => $maxId,
                'endRowHash' => (string)$startRow['previousHash'],
                'rotatedAt' => Carbon::now('UTC')->toDateTime(),
            ]),
        );

        return $deleted;
    }
}
