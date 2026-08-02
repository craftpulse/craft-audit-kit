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
 * GeoResult is the immutable value object describing the geolocation of an IP
 * address. Every field is nullable: a country-level database leaves `region`
 * null, and a lookup that resolves the IP but not the country still returns a
 * GeoResult with null members rather than a bare null.
 *
 * Only `countryCode` + `region` are ever persisted (country/region-only, no
 * city, no raw IP). The human-readable `country` name is carried for display
 * surfaces that want "United States" rather than "US".
 *
 * @author CraftPulse
 * @since 1.0.0
 */
final class GeoResult
{
    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param string|null $country the human-readable country name, or null
     * @param string|null $countryCode the ISO 3166-1 alpha-2 code, or null
     * @param string|null $region the subdivision / region name, or null
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function __construct(
        public readonly ?string $country = null,
        public readonly ?string $countryCode = null,
        public readonly ?string $region = null,
    ) {
    }
}
