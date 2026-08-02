<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\engine\geo;

/**
 * NullGeoProvider is the null-object {@see GeoProviderInterface}: `lookup()`
 * always returns `null`. It is the kit default so {@see \craftpulse\auditkit\engine\ContextCapturer}
 * never has to branch on "is a provider present" — with no real provider
 * injected, geo enrichment is simply absent.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
final class NullGeoProvider implements GeoProviderInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function lookup(string $ip): ?GeoResult
    {
        return null;
    }
}
