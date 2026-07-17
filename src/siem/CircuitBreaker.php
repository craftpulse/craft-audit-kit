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
use yii\caching\CacheInterface;

/**
 * CircuitBreaker is a cache-backed failure counter that trips after a threshold
 * of consecutive failures and stays open for a cooldown window, after which one
 * probe is allowed (half-open). It is the generalisation of Password Policy's
 * SIEM/webhook circuit breaker, reduced to the cache mechanism — a consumer that
 * also wants a durable DB mirror of the state layers that on top in its own
 * forwarder record.
 *
 * Each protected target (a forwarder id, an endpoint id) owns a cache key. A
 * failure increments the counter; crossing the threshold opens the circuit by
 * stamping an open-at timestamp. [[isOpen()]] reports open until the cooldown
 * elapses, then reports half-open once (allowing a single probe) before either a
 * success resets it or a failure re-opens it.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class CircuitBreaker
{
    // Const Properties
    // =========================================================================

    /**
     * @var string Cache key prefix for the failure counter.
     *
     * @since 1.0.0
     */
    public const CACHE_KEY_FAILURES = 'auditkit:circuit:failures:';

    /**
     * @var string Cache key prefix for the open-at timestamp.
     *
     * @since 1.0.0
     */
    public const CACHE_KEY_OPEN_AT = 'auditkit:circuit:openat:';

    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param int $threshold consecutive failures that open the circuit
     * @param int $cooldownSeconds how long the circuit stays open before a probe
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function __construct(
        private readonly int $threshold = 5,
        private readonly int $cooldownSeconds = 300,
    ) {
    }

    /**
     * Returns whether the circuit is currently open (blocking sends). A circuit
     * past its cooldown reports closed so one probe can go through — the next
     * failure re-opens it, a success clears it.
     *
     * @param string $key the target identifier
     * @return bool
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function isOpen(string $key): bool
    {
        $openAt = $this->_cache()->get(self::CACHE_KEY_OPEN_AT . $key);

        if ($openAt === false) {
            return false;
        }

        return ((int)$openAt + $this->cooldownSeconds) > time();
    }

    /**
     * Records a failed send. Increments the counter and opens the circuit when
     * the threshold is crossed. Returns whether the circuit is now open.
     *
     * @param string $key the target identifier
     * @return bool whether the circuit opened (or remains open)
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function recordFailure(string $key): bool
    {
        $cache = $this->_cache();
        $next = (int)($cache->get(self::CACHE_KEY_FAILURES . $key) ?: 0) + 1;
        $cache->set(self::CACHE_KEY_FAILURES . $key, (string)$next, $this->cooldownSeconds);

        if ($next >= $this->threshold) {
            $cache->set(self::CACHE_KEY_OPEN_AT . $key, (string)time(), $this->cooldownSeconds);

            return true;
        }

        return false;
    }

    /**
     * Records a successful send, clearing the failure counter and closing the
     * circuit.
     *
     * @param string $key the target identifier
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function recordSuccess(string $key): void
    {
        $cache = $this->_cache();
        $cache->delete(self::CACHE_KEY_FAILURES . $key);
        $cache->delete(self::CACHE_KEY_OPEN_AT . $key);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the application cache component, narrowed for static analysis.
     *
     * @return CacheInterface
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private function _cache(): CacheInterface
    {
        $cache = Craft::$app->getCache();
        assert($cache instanceof CacheInterface);

        return $cache;
    }
}
