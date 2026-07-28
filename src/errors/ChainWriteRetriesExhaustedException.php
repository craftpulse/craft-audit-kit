<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\errors;

use RuntimeException;
use Throwable;

/**
 * Thrown by {@see \craftpulse\auditkit\engine\ChainWriter::write()} when its
 * bounded retry budget is exhausted against repeated MySQL serialization
 * failures (deadlocks, lock-wait timeouts) without a successful commit.
 *
 * `ChainWriter` retries a bounded number of times with a fresh tail-read (and
 * fresh `previousHash` / `rowHash`) on every attempt, because InnoDB's
 * deadlock detector can abort a waiting transaction under real concurrent
 * load rather than let it wait indefinitely (see `ChainWriter`'s class
 * docblock "Concurrency" section for the mechanism). A caller must still be
 * able to distinguish "every attempt failed" from any other throwable —
 * catching this exception specifically and requeueing the write (Craft's own
 * queue TTR/attempt-count retry, or an explicit re-dispatch) is the intended
 * response. Catching the generic `\Throwable` this class extends and merely
 * logging it recreates the exact silent-loss failure mode this class exists
 * to prevent.
 *
 * @author CraftPulse
 * @since 1.0.1
 */
class ChainWriteRetriesExhaustedException extends RuntimeException
{
    // Public Properties
    // =========================================================================

    /**
     * @var int the number of attempts made before giving up
     */
    public readonly int $attempts;

    // Public Methods
    // =========================================================================

    /**
     * @param int $attempts the number of attempts made before giving up
     * @param Throwable $previous the last serialization failure encountered
     *
     * @author CraftPulse
     * @since 1.0.1
     */
    public function __construct(int $attempts, Throwable $previous)
    {
        $this->attempts = $attempts;

        parent::__construct(
            sprintf(
                'ChainWriter exhausted %d attempt(s) against repeated serialization failures: %s',
                $attempts,
                $previous->getMessage(),
            ),
            0,
            $previous,
        );
    }
}
