<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\events;

use craftpulse\auditkit\audit\AuditSinkInterface;
use yii\base\Event;

/**
 * RegisterAuditSinksEvent collects the audit sinks a consuming plugin
 * contributes to the audit {@see \craftpulse\auditkit\services\Bus}.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class RegisterAuditSinksEvent extends Event
{
    // Public Properties
    // =========================================================================

    /**
     * @var AuditSinkInterface[] The registered audit sinks.
     *
     * @since 1.0.0
     */
    public array $sinks = [];
}
