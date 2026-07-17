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
use DateTime;
use DateTimeZone;

/**
 * ChainVerifier walks a SHA-256 forward chain and reports whether every row
 * verifies against its canonical-payload hash. It is the extraction of Password
 * Policy's console verifier (`AuditController::_walkChain()`): same
 * first-divergence stop, same genesis / bounded-start / retention-boundary
 * first-row handling, same exit-code contract. A consumer's console command or
 * CP utility wraps it and owns the UX; the walk logic stays here so PP, Ledger,
 * and Reeve verify identically.
 *
 * Open-source mandate: the verifier is deliberately readable and unpaywalled —
 * the credibility of the chain rests on auditors being able to read and run it.
 * Hash recomputation goes through {@see Canonicalizer} (the writer's code path);
 * never inline a "fast path" verifier, because drift between writer and verifier
 * silently invalidates the chain.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class ChainVerifier
{
    // Const Properties
    // =========================================================================

    /**
     * Exit code for a chain break. Literal `1` — the documented contract is
     * `0` valid / `1` chain break / `2` unreadable. Published as a constant so
     * a Yii revision can't accidentally rebind it.
     *
     * @var int
     * @since 1.0.0
     */
    public const EXIT_CHAIN_BREAK = 1;

    /**
     * Exit code for a clean pass (full or partial-with-acceptable-boundary).
     *
     * @var int
     * @since 1.0.0
     */
    public const EXIT_OK = 0;

    /**
     * Exit code for an unreadable / schema-drift / malformed-payload case.
     * Distinct from {@see EXIT_CHAIN_BREAK} so CI can route the two failure
     * modes differently (chain break = page security, unreadable = page ops).
     *
     * @var int
     * @since 1.0.0
     */
    public const EXIT_UNREADABLE = 2;

    /**
     * Safety margin (seconds) added to the retention window when deciding
     * whether a non-genesis first surviving row's `previousHash` is an
     * acceptable retention boundary. Tolerates clock drift between the
     * prune-cron host and the verifier host without papering over tampering —
     * a recent first row whose `previousHash` doesn't anchor anywhere is still
     * a break.
     *
     * @var int
     * @since 1.0.0
     */
    public const RETENTION_BOUNDARY_SAFETY_MARGIN_SECONDS = 86400;

    // Public Methods
    // =========================================================================

    /**
     * Walks the given rows in id-ascending order and returns the verification
     * result. Stops at the first break — does not continue past a tampered row.
     *
     * Each row must be an array carrying at least `id`, `previousHash`,
     * `rowHash`, and `dateCreated`. The `$rebuildPayload` closure reconstructs
     * the exact canonical key set the writer hashed for that row (the consumer
     * owns the payload shape, so it owns the rebuild).
     *
     * First-row boundary handling accepts three shapes: the genesis sentinel;
     * an explicit bounded start (`$isFullWalk` false — the caller asked to start
     * mid-chain, so the stored `previousHash` is trusted as the anchor); or, in
     * full-walk mode, a row older than `retentionDays` widened by the safety
     * margin (its anchor row was legitimately pruned).
     *
     * @param iterable<array<string, mixed>> $rows rows in id-ascending order
     * @param callable(array<string, mixed>): array<string, mixed> $rebuildPayload
     * rebuilds a row's canonical payload
     * @param bool $isFullWalk whether the walk starts at the genesis row (no
     * lower id bound was applied)
     * @param int $retentionDays the configured retention window, for the
     * retention-boundary tolerance
     * @return ChainVerifierResult
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function verify(
        iterable $rows,
        callable $rebuildPayload,
        bool $isFullWalk = true,
        int $retentionDays = 365,
    ): ChainVerifierResult {
        $previousHash = Canonicalizer::GENESIS_PREVIOUS_HASH;
        $isFirstRow = true;
        $verifiedRows = 0;
        $totalRows = 0;

        foreach ($rows as $row) {
            $totalRows++;
            $expectedHash = hash('sha256', Canonicalizer::canonicalize($rebuildPayload($row)) . (string)$row['previousHash']);

            if ($isFirstRow) {
                $isFirstRow = false;

                $isGenesis = $row['previousHash'] === Canonicalizer::GENESIS_PREVIOUS_HASH;
                $isBoundedStart = !$isFullWalk;
                $isRetentionBoundary = !$isGenesis
                    && $isFullWalk
                    && $this->_isAcceptableRetentionBoundary($row, $retentionDays);

                if (!$isGenesis && !$isBoundedStart && !$isRetentionBoundary) {
                    return new ChainVerifierResult(
                        exitCode: self::EXIT_CHAIN_BREAK,
                        totalRows: $totalRows,
                        verifiedRows: $verifiedRows,
                        breakId: (int)$row['id'],
                        breakReason: 'previousHash mismatch',
                        expectedHash: $expectedHash,
                        storedHash: (string)$row['rowHash'],
                    );
                }
            } elseif ($row['previousHash'] !== $previousHash) {
                return new ChainVerifierResult(
                    exitCode: self::EXIT_CHAIN_BREAK,
                    totalRows: $totalRows,
                    verifiedRows: $verifiedRows,
                    breakId: (int)$row['id'],
                    breakReason: 'previousHash mismatch',
                    expectedHash: $expectedHash,
                    storedHash: (string)$row['rowHash'],
                );
            }

            if ($expectedHash !== $row['rowHash']) {
                return new ChainVerifierResult(
                    exitCode: self::EXIT_CHAIN_BREAK,
                    totalRows: $totalRows,
                    verifiedRows: $verifiedRows,
                    breakId: (int)$row['id'],
                    breakReason: 'rowHash mismatch',
                    expectedHash: $expectedHash,
                    storedHash: (string)$row['rowHash'],
                );
            }

            $verifiedRows++;
            $previousHash = (string)$row['rowHash'];
        }

        return new ChainVerifierResult(
            exitCode: self::EXIT_OK,
            totalRows: $totalRows,
            verifiedRows: $verifiedRows,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns whether a non-genesis first surviving row is an acceptable
     * retention-purge boundary: full-walk mode, and the row's `dateCreated` is
     * older than `now - retentionDays + safety margin`. The margin WIDENS the
     * acceptance window forward so a head the prune left standing still verifies
     * despite clock drift, without accepting a recent unanchored row.
     *
     * @param array<string, mixed> $row
     * @param int $retentionDays
     * @return bool
     *
     * @throws \Exception when DateTime parsing fails on a malformed value
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private function _isAcceptableRetentionBoundary(array $row, int $retentionDays): bool
    {
        $cutoff = Carbon::now('UTC')
            ->subDays($retentionDays)
            ->addSeconds(self::RETENTION_BOUNDARY_SAFETY_MARGIN_SECONDS);

        $rowCreated = new DateTime((string)$row['dateCreated'], new DateTimeZone('UTC'));

        return $rowCreated->getTimestamp() < $cutoff->getTimestamp();
    }
}
