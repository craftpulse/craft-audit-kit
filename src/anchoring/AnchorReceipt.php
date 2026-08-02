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
 * AnchorReceipt is the immutable evidence an {@see AnchorProviderInterface}
 * returns after committing a Merkle root to an external system: which provider,
 * which root, when, an external reference (a TSA token, an S3 object key), and
 * the raw provider response for archival. A Certificate of Integrity embeds one
 * receipt per anchor backend.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
final class AnchorReceipt
{
    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param string $provider the provider handle (e.g. `rfc3161`, `s3-object-lock`)
     * @param string $merkleRoot the hex Merkle root that was anchored
     * @param int $anchoredAt the unix timestamp the anchor was obtained
     * @param string $reference the external reference (object key, TSA serial)
     * @param string $rawResponse the raw provider response bytes (base64 for
     * binary), for archival and independent verification
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $merkleRoot,
        public readonly int $anchoredAt,
        public readonly string $reference,
        public readonly string $rawResponse,
    ) {
    }

    /**
     * Returns the receipt as a plain array for certificate embedding.
     *
     * @return array<string, mixed>
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'merkleRoot' => $this->merkleRoot,
            'anchoredAt' => $this->anchoredAt,
            'reference' => $this->reference,
            'rawResponse' => $this->rawResponse,
        ];
    }
}
