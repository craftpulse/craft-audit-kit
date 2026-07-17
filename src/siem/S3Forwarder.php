<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\siem;

use Carbon\Carbon;
use Craft;
use Throwable;

/**
 * S3Forwarder ships a canonical audit body to an object store as a
 * time-partitioned object (`{prefix}/YYYY/MM/DD/{eventId}.json`). It is the S3
 * arm of the SIEM surface, sitting behind {@see S3ClientInterface} so the free
 * base never requires `aws/aws-sdk-php` — a consumer injects a concrete client
 * (an SDK adapter or an S3-compatible store).
 *
 * With no client injected the forwarder no-ops with a clear error result rather
 * than a fatal, so an install that lists S3 shipping in the UI but never wired
 * the SDK degrades gracefully. {@see isSdkAvailable()} lets a consumer report
 * the missing dependency to an operator.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class S3Forwarder
{
    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param S3ClientInterface|null $client the object-store client, or null
     * when no SDK adapter is wired
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function __construct(
        private readonly ?S3ClientInterface $client = null,
    ) {
    }

    /**
     * Returns whether the AWS SDK is installed (a consumer can surface this to
     * explain a missing S3 capability without a fatal).
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function isSdkAvailable(): bool
    {
        return class_exists('Aws\\S3\\S3Client');
    }

    /**
     * Ships the body to the store under a time-partitioned key. Returns a
     * clear-error result when no client is wired.
     *
     * @param string $bucket the target bucket
     * @param string $keyPrefix the key prefix (e.g. `audit`)
     * @param string $eventId the stable event id (audit row uid), used as the
     * object filename
     * @param string $body the canonical JSON body
     * @param array<string, mixed> $extraArgs adapter-specific args (Object Lock
     * retention)
     * @return ForwardResult
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function forward(string $bucket, string $keyPrefix, string $eventId, string $body, array $extraArgs = []): ForwardResult
    {
        if ($this->client === null) {
            return new ForwardResult(
                success: false,
                errorMessage: 'No S3 client is available. Install aws/aws-sdk-php and inject an S3ClientInterface adapter to enable S3 shipping.',
            );
        }

        $now = Carbon::now('UTC');
        $key = sprintf('%s/%s/%s.json', rtrim($keyPrefix, '/'), $now->format('Y/m/d'), $eventId);

        try {
            $this->client->putObject($bucket, $key, $body, 'application/json', $extraArgs);

            return new ForwardResult(success: true, statusCode: 200);
        } catch (Throwable $e) {
            Craft::warning('S3 forward failed: ' . $e->getMessage(), 'audit-kit');

            return new ForwardResult(success: false, errorMessage: $e->getMessage());
        }
    }
}
