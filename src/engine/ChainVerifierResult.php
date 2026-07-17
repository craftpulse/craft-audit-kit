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

/**
 * ChainVerifierResult is the immutable outcome of a {@see ChainVerifier} walk.
 * It carries the exit code (matching the verifier's `EXIT_*` constants so a
 * console wrapper can return it directly), the row counts, and — on a break —
 * the offending row's id, the divergence reason, and the expected vs stored
 * hash for a diagnostic.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
final class ChainVerifierResult
{
    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param int $exitCode one of {@see ChainVerifier}'s `EXIT_*` constants
     * @param int $totalRows rows walked (in range)
     * @param int $verifiedRows rows that verified before any break
     * @param int|null $breakId the offending row's id, or null on a clean pass
     * @param string|null $breakReason the divergence reason, or null
     * @param string|null $expectedHash the recomputed hash at the break, or null
     * @param string|null $storedHash the stored hash at the break, or null
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly int $totalRows,
        public readonly int $verifiedRows,
        public readonly ?int $breakId = null,
        public readonly ?string $breakReason = null,
        public readonly ?string $expectedHash = null,
        public readonly ?string $storedHash = null,
    ) {
    }

    /**
     * Returns whether the chain verified cleanly.
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function isValid(): bool
    {
        return $this->exitCode === ChainVerifier::EXIT_OK;
    }
}
