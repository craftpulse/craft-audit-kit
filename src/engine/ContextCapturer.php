<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\engine;

use Craft;
use craftpulse\auditkit\engine\geo\GeoProviderInterface;
use craftpulse\auditkit\engine\geo\GeoResult;
use craftpulse\auditkit\engine\geo\NullGeoProvider;

/**
 * ContextCapturer resolves the privacy-safe request + actor context every audit
 * write needs: HMAC'd IP and user-agent fingerprints, an HMAC'd actor identifier
 * for post-deletion correlation, and optional country/region geo enrichment. It
 * is the extraction of Password Policy's `AuditContext` +
 * `_hashUserIdentifier()` + `_resolveAuditPiiKey()` + `_requestFingerprint()`
 * patterns, generalised so each consumer keeps its own PII-key env var.
 *
 * PII-key resolution: the consumer passes its configured key value (which may
 * be an env reference such as `$CRAFT_LEDGER_PII_KEY`). The capturer resolves it
 * via `Craft::parseEnv()` and falls back to the general `securityKey` when
 * unset, so a dev install that skipped key generation still produces hashable
 * rows. Rotating the dedicated key destroys historical correlation without
 * touching session signing, CSRF, or asset URLs — the USP-grade privacy lever.
 *
 * Console/queue-safe: [[requestFingerprint()]] returns `[null, null]` outside a
 * web request, and every HMAC helper tolerates a missing subject.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class ContextCapturer
{
    // Private Properties
    // =========================================================================

    /**
     * @var GeoProviderInterface The injected geo provider (null-object by
     * default).
     *
     * @since 1.0.0
     */
    private readonly GeoProviderInterface $_geoProvider;

    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param string|null $configuredPiiKey the consumer's configured PII-key
     * setting value, which may be an env reference; null falls back to
     * `securityKey`
     * @param GeoProviderInterface|null $geoProvider an optional geo provider;
     * defaults to {@see NullGeoProvider} (no enrichment)
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function __construct(
        private readonly ?string $configuredPiiKey = null,
        ?GeoProviderInterface $geoProvider = null,
    ) {
        $this->_geoProvider = $geoProvider ?? new NullGeoProvider();
    }

    /**
     * Resolves the geolocation of an IP through the injected provider, or null
     * when no provider is injected or the lookup is unresolvable.
     *
     * @param string $ip the raw IP address
     * @return GeoResult|null
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function geo(string $ip): ?GeoResult
    {
        return $this->_geoProvider->lookup($ip);
    }

    /**
     * Returns HMAC-SHA-256 of a user's email for post-deletion correlation, or
     * null when the user is gone or has no email. The immutable HMAC — not the
     * mutable FK int — is what enters a chain payload, so a GDPR erasure that
     * nulls the FK does not recompute a different rowHash.
     *
     * @param int $userId
     * @return string|null
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function hashUserIdentifier(int $userId): ?string
    {
        $user = Craft::$app->getUsers()->getUserById($userId);

        if ($user === null || $user->email === null) {
            return null;
        }

        return $this->hashValue($user->email);
    }

    /**
     * Returns HMAC-SHA-256 of an arbitrary value under the resolved PII key.
     * Used for IP and user-agent fingerprints — a bare SHA-256 over IPv4's
     * 32-bit space is rainbow-tableable, and keying it makes rotation destroy
     * correlation.
     *
     * @param string $value
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function hashValue(string $value): string
    {
        return hash_hmac('sha256', $value, $this->resolvePiiKey());
    }

    /**
     * Returns `[ipHash, userAgentHash]` for the current request, or
     * `[null, null]` in a console request.
     *
     * @return array{0: string|null, 1: string|null}
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function requestFingerprint(): array
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return [null, null];
        }

        /** @var \craft\web\Request $request */
        $ip = $request->getUserIP();
        $userAgent = $request->getUserAgent();

        return [
            $ip !== null ? $this->hashValue($ip) : null,
            $userAgent !== null ? $this->hashValue($userAgent) : null,
        ];
    }

    /**
     * Resolves the HMAC key for PII hashing — the consumer's configured key
     * (env-resolved via `Craft::parseEnv()`) with a `securityKey` fallback.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function resolvePiiKey(): string
    {
        if ($this->configuredPiiKey !== null && $this->configuredPiiKey !== '') {
            $resolved = Craft::parseEnv($this->configuredPiiKey);

            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }

            return $this->configuredPiiKey;
        }

        return Craft::$app->getConfig()->getGeneral()->securityKey;
    }
}
