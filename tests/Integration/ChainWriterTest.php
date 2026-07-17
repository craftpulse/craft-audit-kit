<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Integration coverage for the chain writer + pruner against a real database:
 * a genesis-rooted multi-row write links correctly, the verifier confirms it
 * end-to-end through the same encoder, and the pruner removes a contiguous id
 * prefix while firing the rotation event with the correct boundary.
 *
 * A dedicated scratch table is created and dropped per test (DDL implicitly
 * commits, so it is torn down in a finally rather than relying on the pest
 * transaction rollback).
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use Carbon\Carbon;
use craft\db\Query;
use craftpulse\auditkit\engine\Canonicalizer;
use craftpulse\auditkit\engine\ChainVerifier;
use craftpulse\auditkit\engine\ChainWriter;
use craftpulse\auditkit\engine\Pruner;
use craftpulse\auditkit\events\ChainRotatedEvent;

const CHAIN_TEST_TABLE = '{{%auditkit_chain_test}}';

/**
 * Creates the scratch chain table.
 */
function createChainTestTable(): void
{
    $db = Craft::$app->getDb();
    dropChainTestTable();
    $db->createCommand()->createTable(CHAIN_TEST_TABLE, [
        'id' => 'integer NOT NULL AUTO_INCREMENT PRIMARY KEY',
        'event' => 'string',
        'dateCreated' => 'datetime NOT NULL',
        'previousHash' => 'char(64) NOT NULL',
        'rowHash' => 'char(64) NOT NULL',
    ])->execute();
}

/**
 * Drops the scratch chain table if present.
 */
function dropChainTestTable(): void
{
    $db = Craft::$app->getDb();
    $raw = $db->getSchema()->getRawTableName(CHAIN_TEST_TABLE);
    if ($db->getSchema()->getTableSchema($raw, true) !== null) {
        $db->createCommand()->dropTable(CHAIN_TEST_TABLE)->execute();
    }
}

/**
 * Writes one chain row via the ChainWriter, returning the inserted id.
 */
function writeChainRow(ChainWriter $writer, string $event, string $dbDateCreated): int
{
    $db = Craft::$app->getDb();

    // Consumers hash dateCreated in the canonical ISO-Z form, not the raw DB
    // datetime — the verifier rebuilds the same form, so the two must agree.
    $canonicalDate = (new DateTime($dbDateCreated, new DateTimeZone('UTC')))
        ->format(Canonicalizer::CANONICAL_DATE_FORMAT);
    $payload = ['event' => $event, 'dateCreated' => $canonicalDate];

    return (int)$writer->write($db, CHAIN_TEST_TABLE, $payload, function(string $previousHash, string $rowHash) use ($db, $event, $dbDateCreated): int {
        $db->createCommand()->insert(CHAIN_TEST_TABLE, [
            'event' => $event,
            'dateCreated' => $dbDateCreated,
            'previousHash' => $previousHash,
            'rowHash' => $rowHash,
        ])->execute();

        return (int)$db->getLastInsertID();
    });
}

it('writes a genesis-rooted chain the verifier accepts end-to-end', function() {
    try {
        createChainTestTable();
        $writer = new ChainWriter();

        writeChainRow($writer, 'a', '2026-01-01 00:00:00');
        writeChainRow($writer, 'b', '2026-01-02 00:00:00');
        writeChainRow($writer, 'c', '2026-01-03 00:00:00');

        $rows = (new Query())->from(CHAIN_TEST_TABLE)->orderBy(['id' => SORT_ASC])->all();

        // First row roots at the genesis sentinel; each links to the prior.
        expect($rows[0]['previousHash'])->toBe(Canonicalizer::GENESIS_PREVIOUS_HASH)
            ->and($rows[1]['previousHash'])->toBe($rows[0]['rowHash'])
            ->and($rows[2]['previousHash'])->toBe($rows[1]['rowHash']);

        $rebuild = fn(array $row): array => [
            'event' => $row['event'],
            'dateCreated' => (new DateTime((string)$row['dateCreated'], new DateTimeZone('UTC')))
                ->format(Canonicalizer::CANONICAL_DATE_FORMAT),
        ];

        $result = (new ChainVerifier())->verify($rows, $rebuild);
        expect($result->isValid())->toBeTrue()->and($result->verifiedRows)->toBe(3);
    } finally {
        dropChainTestTable();
    }
});

it('prunes a contiguous id prefix and fires the rotation event', function() {
    try {
        createChainTestTable();
        $writer = new ChainWriter();
        $db = Craft::$app->getDb();

        // Two old rows past retention, one recent row.
        $old = Carbon::now('UTC')->subDays(400)->format('Y-m-d H:i:s');
        $old2 = Carbon::now('UTC')->subDays(399)->format('Y-m-d H:i:s');
        $recent = Carbon::now('UTC')->format('Y-m-d H:i:s');

        $id1 = writeChainRow($writer, 'a', $old);
        $id2 = writeChainRow($writer, 'b', $old2);
        writeChainRow($writer, 'c', $recent);

        $captured = null;
        $pruner = new Pruner();
        $pruner->on(Pruner::EVENT_CHAIN_ROTATED, function(ChainRotatedEvent $event) use (&$captured): void {
            $captured = $event;
        });

        $deleted = $pruner->prune($db, CHAIN_TEST_TABLE, 365, function(array $ids) use ($db): int {
            return $db->createCommand()->delete(CHAIN_TEST_TABLE, ['id' => $ids])->execute();
        });

        expect($deleted)->toBe(2);

        $survivors = (new Query())->select(['id'])->from(CHAIN_TEST_TABLE)->column();
        expect($survivors)->toHaveCount(1);

        // The rotation boundary: end at the highest deleted id, start at the
        // surviving head, endRowHash is the survivor's previousHash.
        expect($captured)->toBeInstanceOf(ChainRotatedEvent::class)
            ->and($captured->endId)->toBe($id2)
            ->and($captured->startId)->toBeGreaterThan($id2);

        $head = (new Query())->from(CHAIN_TEST_TABLE)->orderBy(['id' => SORT_ASC])->one();
        expect($captured->endRowHash)->toBe($head['previousHash']);
    } finally {
        dropChainTestTable();
    }
});
