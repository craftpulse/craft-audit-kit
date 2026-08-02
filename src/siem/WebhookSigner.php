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
 * WebhookSigner computes and verifies the HMAC signature for a signed webhook
 * delivery. It is the extraction of Password Policy's webhook signing: the
 * signature is `sha256=<hex>` where `<hex>` is
 * `hash_hmac('sha256', "{timestamp}.{eventId}.{body}", secret)`. Binding the
 * timestamp and event id into the signed string defeats replay and body-swap
 * attacks; the canonical `body` is the same byte sequence the syslog framer and
 * the JSONL export emit.
 *
 * Secret rotation with a grace window
 * -----------------------------------
 * [[sign()]] always signs with the current secret. [[verify()]] accepts a
 * signature made with EITHER the current or the previous secret, so a consumer
 * mid-rotation can hand out a new secret while receivers still validating with
 * the old one keep working until the grace window closes and the previous secret
 * is dropped. Comparison is constant-time.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class WebhookSigner
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the `sha256=<hex>` signature for a delivery.
     *
     * @param string $timestamp the unix-epoch-seconds timestamp header value
     * @param string $eventId the stable event id (the audit row uid)
     * @param string $body the canonical JSON body
     * @param string $secret the current signing secret
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function sign(string $timestamp, string $eventId, string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $eventId . '.' . $body, $secret);
    }

    /**
     * Verifies a presented signature against the current secret and, when given,
     * the previous secret (rotation grace). Constant-time comparison.
     *
     * @param string $signature the presented `sha256=<hex>` signature
     * @param string $timestamp the timestamp header value
     * @param string $eventId the event id
     * @param string $body the canonical JSON body
     * @param string $currentSecret the current signing secret
     * @param string|null $previousSecret the previous secret, during the grace
     * window
     * @return bool
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function verify(
        string $signature,
        string $timestamp,
        string $eventId,
        string $body,
        string $currentSecret,
        ?string $previousSecret = null,
    ): bool {
        if (hash_equals($this->sign($timestamp, $eventId, $body, $currentSecret), $signature)) {
            return true;
        }

        if ($previousSecret !== null && $previousSecret !== '') {
            return hash_equals($this->sign($timestamp, $eventId, $body, $previousSecret), $signature);
        }

        return false;
    }
}
