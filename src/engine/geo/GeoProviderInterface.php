<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\engine\geo;

/**
 * GeoProviderInterface is the optional geo-enrichment seam behind
 * {@see \craftpulse\auditkit\engine\ContextCapturer}. It is decoupled so a
 * consumer can inject a MaxMind / DB-IP file provider, an external API, or a
 * fake in tests without the capturer changing.
 *
 * Total — never throws. A provider MUST return `null` for any unresolvable
 * input: missing/unreadable database, unknown IP, private or reserved IP,
 * malformed IP, or any internal `\Throwable`. Geo is strictly best-effort
 * enrichment layered on top of capture; it must never propagate an exception
 * into a write path. The fail-soft contract lives here so every implementation
 * honours it.
 *
 * The kit ships no concrete file-backed provider (that would pull a geo
 * database dependency into the free base). {@see NullGeoProvider} is the
 * default; consumers wanting real enrichment inject their own implementation.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
interface GeoProviderInterface
{
    // Public Methods
    // =========================================================================

    /**
     * Resolves the geolocation of an IP address, returning `null` for any
     * unresolvable input. Implementations must swallow every `\Throwable`
     * internally and return `null`; a thrown exception is a contract violation.
     *
     * @param string $ip the raw IP address to resolve
     * @return GeoResult|null the resolved geolocation, or null when unresolvable
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function lookup(string $ip): ?GeoResult;
}
