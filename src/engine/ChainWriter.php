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

use craftpulse\auditkit\errors\ChainWriteRetriesExhaustedException;
use Throwable;
use yii\db\Connection;
use yii\db\Exception as DbException;

/**
 * ChainWriter performs one serialized write onto a SHA-256 forward chain. It
 * owns the mechanism — the `SELECT ... FOR UPDATE` tail read, the
 * `rowHash = sha256(canonicalPayload . previousHash)` computation, and the
 * wrapping transaction — while the consumer owns the payload shape and the
 * storage. This is the extraction of Password Policy's proven in-transaction
 * chain write, generalised so PP keeps its 9-key payload and Ledger its 14-key
 * payload with byte-identical linkage semantics.
 *
 * Concurrency: the tail read takes a `FOR UPDATE` lock on the chain's current
 * last row (or the equivalent empty-range lock when the table is still
 * empty). Under light contention this does serialize two writers exactly as
 * that mechanism suggests: the second waits for the first's commit, then
 * reads the new tail as its own `previousHash`. Under real concurrent load it
 * does NOT hold unconditionally: the `ORDER BY id DESC LIMIT 1` predicate
 * takes InnoDB gap/next-key locks alongside the row lock, and concurrent
 * `INSERT`s taking their own insert-intention locks on that same gap create
 * genuine lock-wait cycles. InnoDB's deadlock detector resolves those cycles
 * by aborting one of the waiting transactions with `SQLSTATE 40001`
 * (`ER_LOCK_DEADLOCK`) — sometimes preceded by `ER_LOCK_WAIT_TIMEOUT` — rather
 * than letting it wait indefinitely. Live reproduction aborted 9 of 12
 * concurrent writers this way at 12-way concurrency, and 18 of 20 at 20-way.
 * `write()` therefore retries a bounded number of times with a fresh
 * tail-read (and fresh `previousHash` / `rowHash`) on every attempt — safe
 * because nothing about the computed hash is reused across attempts — and
 * throws {@see ChainWriteRetriesExhaustedException} once the retry budget is
 * exhausted, so a caller can requeue the write rather than treat it as having
 * silently succeeded. This holds regardless of write source (request path,
 * queue job, sink): the retry lives in the one shared mechanism every
 * consumer calls through.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class ChainWriter
{
    // Const Properties
    // =========================================================================

    /**
     * @var int the default bounded retry budget for {@see write()}
     * @since 1.0.1
     */
    public const DEFAULT_MAX_ATTEMPTS = 5;

    /**
     * @var int the backoff floor, in microseconds, before jitter is applied
     * @since 1.0.1
     */
    private const BASE_BACKOFF_MICROSECONDS = 10_000;

    /**
     * @var int the backoff ceiling, in microseconds, before jitter is applied
     * @since 1.0.1
     */
    private const MAX_BACKOFF_MICROSECONDS = 200_000;

    // Private Properties
    // =========================================================================

    /**
     * @var int the bounded retry budget for {@see write()}
     */
    private int $_maxAttempts;

    /**
     * @var callable(int): void invoked between attempts with the just-failed
     * attempt number, in place of a real `usleep()` call
     */
    private $_sleeper;

    // Public Methods
    // =========================================================================

    /**
     * @param int $maxAttempts the bounded retry budget: the number of times
     * {@see write()} will attempt the write, including the first attempt,
     * before throwing {@see ChainWriteRetriesExhaustedException}
     * @param ?callable(int): void $sleeper invoked between attempts with the
     * just-failed attempt number; defaults to a full-jitter exponential
     * `usleep()` backoff. Overridable so tests can assert on backoff behavior
     * without a real suite-slowing sleep.
     *
     * @author CraftPulse
     * @since 1.0.1
     */
    public function __construct(int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS, ?callable $sleeper = null)
    {
        $this->_maxAttempts = $maxAttempts;
        $this->_sleeper = $sleeper ?? static function(int $attempt): void {
            usleep(self::_backoffMicroseconds($attempt));
        };
    }

    /**
     * Writes one row onto the chain and returns whatever the persist closure
     * returns (typically the new row's primary key).
     *
     * Each attempt runs inside its own `$db->transaction()`. The tail
     * `rowHash` is read with `FOR UPDATE` so concurrent writers can't share a
     * `previousHash`; the genesis sentinel stands in when the table is empty.
     * The consumer's `$persist` closure runs INSIDE the transaction — it
     * receives the resolved `$previousHash` and computed `$rowHash` and
     * performs the actual insert (element save, record insert, whatever the
     * consumer's storage is). Anything it throws rolls the attempt's
     * transaction back.
     *
     * A serialization failure (MySQL deadlock 1213/`SQLSTATE 40001`, or a
     * lock-wait timeout 1205) retries the whole attempt from scratch,
     * re-reading the tail and recomputing both hashes, up to `$maxAttempts`
     * (see the constructor) times. `$persist` must therefore be safe to
     * invoke more than once; nothing it did in an aborted, rolled-back
     * attempt is visible to the next one. Any other throwable propagates
     * immediately without retry.
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
     * @throws Throwable anything non-retryable the persist closure throws
     * (rolls back)
     * @throws ChainWriteRetriesExhaustedException when every attempt within
     * the retry budget fails with a serialization failure
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function write(Connection $db, string $table, array $canonicalPayload, callable $persist): mixed
    {
        $rawTableName = $db->getSchema()->getRawTableName($table);
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->_attemptWrite($db, $rawTableName, $canonicalPayload, $persist);
            } catch (Throwable $e) {
                if (!self::_isRetryableLockFailure($e)) {
                    throw $e;
                }

                if ($attempt >= $this->_maxAttempts) {
                    throw new ChainWriteRetriesExhaustedException($attempt, $e);
                }

                ($this->_sleeper)($attempt);
            }
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Runs a single write attempt inside its own transaction: the locking
     * tail-read, the hash computation, and the consumer's persist closure.
     *
     * @param Connection $db the database connection
     * @param string $rawTableName the chain table's raw (unprefixed-alias) name
     * @param array<string, mixed> $canonicalPayload the exact key set to hash
     * @param callable(string, string): mixed $persist closure invoked inside the
     * transaction with `($previousHash, $rowHash)`; its return value is returned
     * @return mixed the persist closure's return value
     *
     * @throws Throwable anything the persist closure or the tail-read throws
     * (rolls back)
     *
     * @author CraftPulse
     * @since 1.0.1
     */
    private function _attemptWrite(Connection $db, string $rawTableName, array $canonicalPayload, callable $persist): mixed
    {
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

    /**
     * Determines whether a throwable represents a retryable MySQL
     * serialization failure: a deadlock (`ER_LOCK_DEADLOCK`, error 1213,
     * `SQLSTATE 40001`) or a lock-wait timeout (`ER_LOCK_WAIT_TIMEOUT`, error
     * 1205). Both are transient consequences of the tail-read's locking, not
     * genuine data problems, and are safe to retry with a fresh transaction.
     *
     * @param Throwable $e the throwable to inspect
     * @return bool whether the throwable should trigger a retry
     *
     * @author CraftPulse
     * @since 1.0.1
     */
    private static function _isRetryableLockFailure(Throwable $e): bool
    {
        if (!$e instanceof DbException) {
            return false;
        }

        $driverErrorCode = $e->errorInfo[1] ?? null;

        if (in_array($driverErrorCode, [1205, 1213], true)) {
            return true;
        }

        return str_contains($e->getMessage(), 'SQLSTATE[40001]')
            || str_contains($e->getMessage(), 'Lock wait timeout exceeded');
    }

    /**
     * Computes a full-jitter exponential backoff delay, in microseconds, for
     * the given (1-indexed) attempt number.
     *
     * @param int $attempt the just-failed attempt number (1-indexed)
     * @return int a microsecond delay, uniformly random between 0 and the
     * exponential backoff ceiling for this attempt
     *
     * @author CraftPulse
     * @since 1.0.1
     */
    private static function _backoffMicroseconds(int $attempt): int
    {
        $exponential = min(
            self::MAX_BACKOFF_MICROSECONDS,
            self::BASE_BACKOFF_MICROSECONDS * (2 ** ($attempt - 1)),
        );

        return random_int(0, (int)$exponential);
    }
}
