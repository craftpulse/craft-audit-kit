<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Deterministic regression coverage for ChainWriter's bounded retry/backoff
 * on MySQL serialization failures (Bug #1, 2026-07-27 smoke run). A real
 * deadlock is a race that can't be forced on demand, so these tests inject a
 * fake `yii\db\Exception` shaped exactly like a real deadlock (same
 * `errorInfo`/`SQLSTATE[40001]` message shape MySQL produces) via the
 * `$persist` closure, which ChainWriter treats identically to a failure from
 * its own tail-read since both happen inside the same transaction attempt.
 *
 * A dedicated scratch table is created and dropped per test (DDL implicitly
 * commits, so it is torn down in a finally rather than relying on the pest
 * transaction rollback).
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\auditkit\engine\Canonicalizer;
use craftpulse\auditkit\engine\ChainWriter;
use craftpulse\auditkit\errors\ChainWriteRetriesExhaustedException;

const RETRY_TEST_TABLE = '{{%auditkit_chain_retry_test}}';

/**
 * Creates the scratch chain table.
 */
function createRetryTestTable(): void
{
    $db = Craft::$app->getDb();
    dropRetryTestTable();
    $db->createCommand()->createTable(RETRY_TEST_TABLE, [
        'id' => 'integer NOT NULL AUTO_INCREMENT PRIMARY KEY',
        'event' => 'string',
        'previousHash' => 'char(64) NOT NULL',
        'rowHash' => 'char(64) NOT NULL',
    ])->execute();
}

/**
 * Drops the scratch chain table if present.
 */
function dropRetryTestTable(): void
{
    $db = Craft::$app->getDb();
    $raw = $db->getSchema()->getRawTableName(RETRY_TEST_TABLE);
    if ($db->getSchema()->getTableSchema($raw, true) !== null) {
        $db->createCommand()->dropTable(RETRY_TEST_TABLE)->execute();
    }
}

/**
 * Builds a `yii\db\Exception` shaped exactly like a real MySQL deadlock:
 * same `errorInfo` triple and the same `SQLSTATE[40001]` message prefix PDO
 * produces, so `ChainWriter`'s detection logic can't distinguish it from the
 * real thing.
 */
function fakeDeadlockException(): yii\db\Exception
{
    return new yii\db\Exception(
        'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction',
        ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'],
        '40001',
    );
}

/**
 * Builds a `yii\db\Exception` shaped like a lock-wait timeout, MySQL's other
 * transient serialization failure.
 */
function fakeLockWaitTimeoutException(): yii\db\Exception
{
    return new yii\db\Exception(
        'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction',
        ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'],
        'HY000',
    );
}

it('retries a transient deadlock and succeeds with a correctly linked row', function() {
    try {
        createRetryTestTable();
        $db = Craft::$app->getDb();

        $recordedSleeps = [];
        $writer = new ChainWriter(sleeper: function(int $attempt) use (&$recordedSleeps): void {
            $recordedSleeps[] = $attempt;
        });

        $calls = 0;
        $id = $writer->write($db, RETRY_TEST_TABLE, ['event' => 'a'], function(string $previousHash, string $rowHash) use (&$calls, $db) {
            $calls++;

            if ($calls < 3) {
                throw fakeDeadlockException();
            }

            $db->createCommand()->insert(RETRY_TEST_TABLE, [
                'event' => 'a',
                'previousHash' => $previousHash,
                'rowHash' => $rowHash,
            ])->execute();

            return (int)$db->getLastInsertID();
        });

        expect($calls)->toBe(3)
            ->and($recordedSleeps)->toBe([1, 2])
            ->and($id)->toBeInt();

        $row = (new craft\db\Query())->from(RETRY_TEST_TABLE)->one();
        expect($row['previousHash'])->toBe(Canonicalizer::GENESIS_PREVIOUS_HASH)
            ->and($row['rowHash'])->toBe(hash('sha256', Canonicalizer::canonicalize(['event' => 'a']) . Canonicalizer::GENESIS_PREVIOUS_HASH));
    } finally {
        dropRetryTestTable();
    }
});

it('retries a lock-wait timeout identically to a deadlock', function() {
    try {
        createRetryTestTable();
        $db = Craft::$app->getDb();
        $writer = new ChainWriter(sleeper: function(): void {
        });

        $calls = 0;
        $writer->write($db, RETRY_TEST_TABLE, ['event' => 'a'], function(string $previousHash, string $rowHash) use (&$calls, $db) {
            $calls++;

            if ($calls < 2) {
                throw fakeLockWaitTimeoutException();
            }

            $db->createCommand()->insert(RETRY_TEST_TABLE, [
                'event' => 'a',
                'previousHash' => $previousHash,
                'rowHash' => $rowHash,
            ])->execute();

            return (int)$db->getLastInsertID();
        });

        expect($calls)->toBe(2);
    } finally {
        dropRetryTestTable();
    }
});

it('throws ChainWriteRetriesExhaustedException once the retry budget is exhausted, leaving no row behind', function() {
    try {
        createRetryTestTable();
        $db = Craft::$app->getDb();
        $writer = new ChainWriter(maxAttempts: 3, sleeper: function(): void {
        });

        $calls = 0;
        $caught = null;

        try {
            $writer->write($db, RETRY_TEST_TABLE, ['event' => 'a'], function() use (&$calls) {
                $calls++;
                throw fakeDeadlockException();
            });
        } catch (ChainWriteRetriesExhaustedException $e) {
            $caught = $e;
        }

        expect($calls)->toBe(3)
            ->and($caught)->toBeInstanceOf(ChainWriteRetriesExhaustedException::class)
            ->and($caught->attempts)->toBe(3)
            ->and($caught->getPrevious())->toBeInstanceOf(yii\db\Exception::class);

        $survivors = (new craft\db\Query())->from(RETRY_TEST_TABLE)->count();
        expect((int)$survivors)->toBe(0);
    } finally {
        dropRetryTestTable();
    }
});

it('does not retry a non-serialization failure and propagates it on the first attempt', function() {
    try {
        createRetryTestTable();
        $db = Craft::$app->getDb();

        $sleptAtAll = false;
        $writer = new ChainWriter(sleeper: function() use (&$sleptAtAll): void {
            $sleptAtAll = true;
        });

        $calls = 0;
        $caught = null;

        try {
            $writer->write($db, RETRY_TEST_TABLE, ['event' => 'a'], function() use (&$calls) {
                $calls++;
                throw new RuntimeException('not a serialization failure');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        expect($calls)->toBe(1)
            ->and($sleptAtAll)->toBeFalse()
            ->and($caught)->not->toBeInstanceOf(ChainWriteRetriesExhaustedException::class)
            ->and($caught?->getMessage())->toBe('not a serialization failure');
    } finally {
        dropRetryTestTable();
    }
});

it('does not retry a yii\db\Exception whose SQLSTATE is unrelated to locking', function() {
    try {
        createRetryTestTable();
        $db = Craft::$app->getDb();
        $writer = new ChainWriter(sleeper: function(): void {
        });

        $calls = 0;
        $caught = null;

        try {
            $writer->write($db, RETRY_TEST_TABLE, ['event' => 'a'], function() use (&$calls) {
                $calls++;

                throw new yii\db\Exception(
                    "SQLSTATE[42S02]: Base table or view not found",
                    ['42S02', 1146, 'Table does not exist'],
                    '42S02',
                );
            });
        } catch (yii\db\Exception $e) {
            $caught = $e;
        }

        expect($calls)->toBe(1)
            ->and($caught)->not->toBeInstanceOf(ChainWriteRetriesExhaustedException::class);
    } finally {
        dropRetryTestTable();
    }
});
