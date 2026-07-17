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

use yii\db\Connection;

/**
 * ChainWriter performs one serialized write onto a SHA-256 forward chain. It
 * owns the mechanism — the `SELECT ... FOR UPDATE` tail read, the
 * `rowHash = sha256(canonicalPayload . previousHash)` computation, and the
 * wrapping transaction — while the consumer owns the payload shape and the
 * storage. This is the extraction of Password Policy's proven in-transaction
 * chain write, generalised so PP keeps its 9-key payload and Ledger its 14-key
 * payload with byte-identical linkage semantics.
 *
 * Concurrency: the tail read locks the latest `rowHash` until the new row's
 * insert commits. Two concurrent writers serialize — the second waits for the
 * first's commit, then reads the new tail as its own `previousHash`. This holds
 * regardless of write source (request path, queue job, sink) because the lock
 * serializes every writer.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class ChainWriter
{
    // Public Methods
    // =========================================================================

    /**
     * Writes one row onto the chain and returns whatever the persist closure
     * returns (typically the new row's primary key).
     *
     * The whole operation runs inside `$db->transaction()`. The tail `rowHash`
     * is read with `FOR UPDATE` so concurrent writers can't share a
     * `previousHash`; the genesis sentinel stands in when the table is empty.
     * The consumer's `$persist` closure runs INSIDE the transaction — it
     * receives the resolved `$previousHash` and computed `$rowHash` and performs
     * the actual insert (element save, record insert, whatever the consumer's
     * storage is). Anything it throws rolls the transaction back.
     *
     * The canonical payload is exactly the key set the consumer wants hashed —
     * ChainWriter does not add, remove, or reorder keys. Mutable FK columns and
     * post-insert enrichment must be excluded by the consumer before calling
     * (see Password Policy's exclusion of `userId` / geo columns).
     *
     * @param Connection $db the database connection
     * @param string $table the chain table, in `{{%handle}}` form
     * @param array<string, mixed> $canonicalPayload the exact key set to hash
     * @param callable(string, string): mixed $persist closure invoked inside the
     * transaction with `($previousHash, $rowHash)`; its return value is returned
     * @return mixed the persist closure's return value
     *
     * @throws \Throwable anything the persist closure throws (rolls back)
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function write(Connection $db, string $table, array $canonicalPayload, callable $persist): mixed
    {
        $rawTableName = $db->getSchema()->getRawTableName($table);
        $result = null;

        $db->transaction(function() use ($db, $rawTableName, $canonicalPayload, $persist, &$result): void {
            // Yii's Query builder doesn't expose FOR UPDATE — issue the locking
            // read as a raw command. The table name is resolved from a
            // compile-time constant, no user input, no injection surface.
            $previousHash = $db
                ->createCommand("SELECT [[rowHash]] FROM {$db->quoteTableName($rawTableName)} ORDER BY [[id]] DESC LIMIT 1 FOR UPDATE")
                ->queryScalar();

            if (!is_string($previousHash) || $previousHash === '') {
                $previousHash = Canonicalizer::GENESIS_PREVIOUS_HASH;
            }

            $rowHash = hash('sha256', Canonicalizer::canonicalize($canonicalPayload) . $previousHash);

            $result = $persist($previousHash, $rowHash);
        });

        return $result;
    }
}
