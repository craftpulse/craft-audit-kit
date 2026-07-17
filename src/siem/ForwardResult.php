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

/**
 * ForwardResult is the immutable outcome of an {@see HttpJsonForwarder} delivery:
 * whether it succeeded, the HTTP status (null when the request never completed),
 * and a bounded, body-free error diagnostic. The request body and any signature
 * are deliberately never carried here — they must not leak through diagnostics.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
final class ForwardResult
{
    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param bool $success whether the delivery returned a 2xx
     * @param int|null $statusCode the HTTP status, or null when unreachable
     * @param string|null $errorMessage a bounded diagnostic, or null on success
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?int $statusCode = null,
        public readonly ?string $errorMessage = null,
    ) {
    }
}
