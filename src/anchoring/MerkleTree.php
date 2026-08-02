<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\anchoring;

use InvalidArgumentException;

/**
 * MerkleTree builds a binary SHA-256 Merkle tree over a batch of chain row
 * hashes and produces the root plus per-leaf inclusion proofs. Batching a
 * window of chain rows into a single root lets the anchoring providers stamp one
 * external anchor per batch (a TSA timestamp, an S3 Object Lock object) that
 * commits to every row in the window — cheaper and more scalable than anchoring
 * each row.
 *
 * Construction rules (pinned by tests):
 *
 * - Leaves are the hex `rowHash` values, hashed as their raw bytes.
 * - An odd node count duplicates the last node (Bitcoin-style) so every level
 *   pairs cleanly.
 * - A parent is `sha256(left_bytes . right_bytes)`, hex-encoded.
 * - A single leaf is its own root.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
final class MerkleTree
{
    // Private Properties
    // =========================================================================

    /**
     * @var string[] The leaf hex hashes.
     *
     * @since 1.0.0
     */
    private readonly array $_leaves;

    /**
     * @var array<int, string[]> The levels, index 0 being the leaves, the last
     * being the single-element root level.
     *
     * @since 1.0.0
     */
    private readonly array $_levels;

    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param string[] $leaves the hex leaf hashes (chain row hashes), in order
     * @throws InvalidArgumentException when the leaf set is empty
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function __construct(array $leaves)
    {
        if ($leaves === []) {
            throw new InvalidArgumentException('A Merkle tree requires at least one leaf.');
        }

        $this->_leaves = array_values($leaves);
        $this->_levels = self::_buildLevels($this->_leaves);
    }

    /**
     * Returns the inclusion proof for the leaf at the given index — the ordered
     * list of sibling hashes plus a left/right position flag, sufficient to
     * recompute the root from the leaf alone.
     *
     * @param int $index the leaf index
     * @return array<int, array{hash: string, position: string}>
     * @throws InvalidArgumentException when the index is out of range
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function proof(int $index): array
    {
        if ($index < 0 || $index >= count($this->_leaves)) {
            throw new InvalidArgumentException("Leaf index {$index} is out of range.");
        }

        $proof = [];
        $position = $index;

        for ($level = 0; $level < count($this->_levels) - 1; $level++) {
            $nodes = $this->_levels[$level];
            $isRight = ($position % 2) === 1;
            $siblingIndex = $isRight ? $position - 1 : $position + 1;

            // Odd tail: the last node is paired with itself.
            $siblingIndex = min($siblingIndex, count($nodes) - 1);

            $proof[] = [
                'hash' => $nodes[$siblingIndex],
                'position' => $isRight ? 'left' : 'right',
            ];

            $position = intdiv($position, 2);
        }

        return $proof;
    }

    /**
     * Returns the Merkle root as a hex string.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function root(): string
    {
        $top = $this->_levels[count($this->_levels) - 1];

        return $top[0];
    }

    // Static Methods
    // =========================================================================

    /**
     * Recomputes a root from a leaf and its inclusion proof — the check a
     * verifier runs. Static so the standalone verifier can call it without a
     * tree instance.
     *
     * @param string $leaf the hex leaf hash
     * @param array<int, array{hash: string, position: string}> $proof
     * @return string the recomputed hex root
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function rootFromProof(string $leaf, array $proof): string
    {
        $hash = $leaf;

        foreach ($proof as $step) {
            $hash = $step['position'] === 'left'
                ? self::_hashPair($step['hash'], $hash)
                : self::_hashPair($hash, $step['hash']);
        }

        return $hash;
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds every tree level from the leaves up to the root.
     *
     * @param string[] $leaves
     * @return array<int, string[]>
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private static function _buildLevels(array $leaves): array
    {
        $levels = [$leaves];

        while (count($levels[count($levels) - 1]) > 1) {
            $current = $levels[count($levels) - 1];
            $next = [];

            for ($i = 0; $i < count($current); $i += 2) {
                $left = $current[$i];
                $right = $current[$i + 1] ?? $left;
                $next[] = self::_hashPair($left, $right);
            }

            $levels[] = $next;
        }

        return $levels;
    }

    /**
     * Hashes a pair of hex nodes as raw bytes, returning the hex parent.
     *
     * @param string $left
     * @param string $right
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private static function _hashPair(string $left, string $right): string
    {
        return hash('sha256', hex2bin($left) . hex2bin($right));
    }
}
