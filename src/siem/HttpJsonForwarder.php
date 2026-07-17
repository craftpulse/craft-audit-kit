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

use Craft;
use Throwable;

/**
 * HttpJsonForwarder POSTs a canonical JSON body to an HTTPS endpoint with
 * caller-supplied headers. It is the generalisation of Password Policy's webhook
 * transport into a neutral HTTP-JSON forwarder that covers a signed webhook
 * (headers from {@see WebhookSigner}), Splunk HEC (an `Authorization: Splunk
 * <token>` header), Datadog (a `DD-API-KEY` header), and any generic
 * token/API-key endpoint — the header set is the only thing that varies.
 *
 * Security guarantees, inherited from the webhook forwarder:
 *
 * - HTTPS-only: the resolved URL is refused unless it begins with `https://`, so
 *   a signed payload never traverses plaintext.
 * - No redirects: a 3xx is a non-success outcome, never followed — a redirect
 *   would re-POST the signed body to an unapproved host.
 * - TLS verification is always on; a 2xx alone is success.
 * - The request body and headers are never echoed into diagnostics.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class HttpJsonForwarder
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Request timeout in seconds.
     *
     * @since 1.0.0
     */
    public const TIMEOUT_SECONDS = 10;

    // Public Methods
    // =========================================================================

    /**
     * POSTs the body to the endpoint and returns the outcome.
     *
     * @param string $url the resolved HTTPS endpoint
     * @param string $body the canonical JSON body
     * @param array<string, string> $headers additional request headers (auth,
     * signature)
     * @return ForwardResult
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function forward(string $url, string $body, array $headers = []): ForwardResult
    {
        if (stripos($url, 'https://') !== 0) {
            return new ForwardResult(
                success: false,
                errorMessage: 'Resolved forward URL is not https://; refusing to send over plaintext.',
            );
        }

        try {
            $client = Craft::createGuzzleClient([
                'verify' => true,
                'http_errors' => false,
                'allow_redirects' => false,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $response = $client->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/json'] + $headers,
                'body' => $body,
            ]);

            $statusCode = $response->getStatusCode();
            $success = $statusCode >= 200 && $statusCode < 300;

            return new ForwardResult(
                success: $success,
                statusCode: $statusCode,
                errorMessage: $success ? null : sprintf('Endpoint returned HTTP %d.', $statusCode),
            );
        } catch (Throwable $e) {
            Craft::warning('HTTP-JSON forward failed: ' . $e->getMessage(), 'audit-kit');

            return new ForwardResult(success: false, errorMessage: $e->getMessage());
        }
    }
}
