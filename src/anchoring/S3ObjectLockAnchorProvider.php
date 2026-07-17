<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\anchoring;

use Carbon\Carbon;
use craftpulse\auditkit\siem\S3ClientInterface;
use RuntimeException;

/**
 * S3ObjectLockAnchorProvider anchors a Merkle root by writing it to an S3 bucket
 * under Object Lock in COMPLIANCE mode with a retention date. Once written, not
 * even the account root can delete or overwrite the object before the retention
 * expires — so an operator cannot rewrite the chain and re-anchor. It shares the
 * {@see S3ClientInterface} seam with the SIEM S3 forwarder, so the free base
 * never requires the AWS SDK; a consumer injects a concrete adapter.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class S3ObjectLockAnchorProvider implements AnchorProviderInterface
{
    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param S3ClientInterface $client the object-store client (Object Lock
     * capable)
     * @param string $bucket the Object Lock bucket
     * @param string $keyPrefix the key prefix for anchor objects
     * @param int $retentionDays the COMPLIANCE-mode retention window in days
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function __construct(
        private readonly S3ClientInterface $client,
        private readonly string $bucket,
        private readonly string $keyPrefix = 'anchors',
        private readonly int $retentionDays = 2555,
    ) {
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function anchor(string $merkleRoot): AnchorReceipt
    {
        $now = Carbon::now('UTC');
        $key = sprintf('%s/%s/%s.json', rtrim($this->keyPrefix, '/'), $now->format('Y/m/d'), $merkleRoot);

        $body = json_encode([
            'merkleRoot' => $merkleRoot,
            'anchoredAt' => $now->toIso8601ZuluString(),
            'algorithm' => 'sha256',
        ], JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new RuntimeException('Failed to encode the S3 anchor body.');
        }

        $retainUntil = $now->copy()->addDays($this->retentionDays);

        $this->client->putObject($this->bucket, $key, $body, 'application/json', [
            'ObjectLockMode' => 'COMPLIANCE',
            'ObjectLockRetainUntilDate' => $retainUntil->toIso8601ZuluString(),
        ]);

        return new AnchorReceipt(
            provider: $this->handle(),
            merkleRoot: $merkleRoot,
            anchoredAt: $now->getTimestamp(),
            reference: $key,
            rawResponse: $body,
        );
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function handle(): string
    {
        return 's3-object-lock';
    }
}
