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

/**
 * AnchorProviderInterface is the pluggable external-anchor seam. Each backend
 * (RFC 3161 TSA, S3 Object Lock, and future providers such as a public
 * blockchain) commits a Merkle root to a system outside the operator's control,
 * so the operator cannot later rewrite the chain and re-anchor without detection.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
interface AnchorProviderInterface
{
    // Public Methods
    // =========================================================================

    /**
     * Commits a Merkle root to the external system and returns the receipt.
     *
     * @param string $merkleRoot the hex Merkle root to anchor
     * @return AnchorReceipt
     * @throws \Throwable on any provider failure
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function anchor(string $merkleRoot): AnchorReceipt;

    /**
     * Returns the provider handle (e.g. `rfc3161`, `s3-object-lock`).
     *
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function handle(): string;
}
