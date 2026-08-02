<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\siem;

/**
 * S3ClientInterface is the object-store seam behind {@see S3Forwarder} (and
 * shared by the anchoring S3 Object Lock provider). It is deliberately tiny so a
 * consumer can wrap `aws/aws-sdk-php` (kept under `suggest`, never `require`),
 * an S3-compatible store, or a fake in tests without the forwarder changing.
 *
 * The kit ships no concrete AWS-backed implementation — that would drag the SDK
 * into the free base. A consumer that wants S3 shipping constructs a thin adapter
 * over `Aws\S3\S3Client` and injects it; with none injected, {@see S3Forwarder}
 * returns a clear "no S3 client available" error rather than a fatal.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
interface S3ClientInterface
{
    // Public Methods
    // =========================================================================

    /**
     * Writes an object to the store.
     *
     * @param string $bucket the bucket name
     * @param string $key the object key
     * @param string $body the object bytes
     * @param string $contentType the object MIME type
     * @param array<string, mixed> $extraArgs adapter-specific args (e.g. Object
     * Lock retention parameters)
     *
     * @throws \Throwable on any store failure
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function putObject(string $bucket, string $key, string $body, string $contentType, array $extraArgs = []): void;
}
