<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\integrations;

use craftpulse\auditkit\audit\AuditEvent;
use craftpulse\auditkit\AuditKit;
use yii\base\Event;

/**
 * AuthKitBridge maps Auth Kit's authentication events onto the audit
 * {@see \craftpulse\auditkit\services\Bus}, so recorders wire a single seam:
 * a recorder registers one {@see \craftpulse\auditkit\audit\AuditSinkInterface}
 * on the bus and receives auth, content, system, and every other event through
 * it — never a second listener on Auth Kit's own sink registry.
 *
 * No hard dependency
 * ------------------
 * Audit Kit `suggest`s Auth Kit, never `require`s it. [[register()]] wires the
 * listener with a compile-time class STRING (`\craftpulse\authkit\services\Audit::class`);
 * a class-string constant expression autoloads nothing. When Auth Kit is absent
 * the target class never loads, its `Audit::EVENT_AFTER_RECORD` never fires, and
 * the closure never runs — a clean no-op with no fatal. The `AuditRecordEvent`
 * that carries the payload only autoloads inside the closure, which only runs
 * when Auth Kit is present.
 *
 * Mapping
 * -------
 * Auth Kit's `AuthEvent` supersets cleanly into {@see AuditEvent}: the neutral
 * `name`, `emitter`, `outcome`, `actorId`, and `details` pass straight through;
 * the auth subject `userId` collapses onto the neutral target pair
 * (`targetType` = `user`, `targetId` = the user id); and the category is the
 * literal `auth`. Auth Kit's two-value outcome (`success` / `failure`) is a
 * strict subset of the kit's three-value outcome, so no translation is needed.
 *
 * Frozen-vocabulary enforcement
 * -----------------------------
 * Only events whose `name` belongs to Auth Kit's frozen vocabulary — the public
 * name constants declared on `AuthEvent` itself — are bridged. `AuthEvent` does
 * not validate names at construction, so third parties can (and do) push
 * foreign names through Auth Kit's sink registry; blindly relaying those would
 * stamp non-auth events into recorders' append-only chains under
 * `category: auth`, a permanent misclassification. The bridge is a consumer of
 * the Auth Kit contract, and the contract's forward-compatibility rule is that
 * consumers ignore unknown names — so it does, with a warning log. The
 * vocabulary is read from the installed `AuthEvent`'s constants via reflection,
 * so additive-minor vocabulary growth in Auth Kit flows through with no kit
 * release.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class AuthKitBridge
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The Auth Kit event name the bridge listens for. Held as a
     * literal, NOT as `Audit::EVENT_AFTER_RECORD`: reading a class constant
     * would autoload `craftpulse\authkit\services\Audit`, defeating the
     * no-hard-dependency guarantee when Auth Kit is absent. The value is fixed
     * by Auth Kit 1.5.0's public contract.
     *
     * @since 1.0.0
     */
    public const AUTH_KIT_EVENT_AFTER_RECORD = 'afterRecord';

    /**
     * @var string The category every bridged authentication event lands under.
     *
     * @since 1.0.0
     */
    public const CATEGORY_AUTH = 'auth';

    /**
     * @var string The neutral target type an auth subject maps onto.
     *
     * @since 1.0.0
     */
    public const TARGET_TYPE_USER = 'user';

    /**
     * @var string The constant-name prefix on `AuthEvent` that declares outcome
     * values rather than event names; excluded from the reflected vocabulary.
     *
     * @since 1.0.0
     */
    private const OUTCOME_CONST_PREFIX = 'OUTCOME_';

    // Static Properties
    // =========================================================================

    /**
     * @var array<string, true>|null Memoized frozen vocabulary, keyed by event
     * name. Null until first use; only ever populated with Auth Kit present.
     */
    private static ?array $vocabulary = null;

    // Static Methods
    // =========================================================================

    /**
     * Registers the Auth Kit listener. A no-op when Auth Kit is absent — the
     * target `Audit::EVENT_AFTER_RECORD` event never fires, so the closure never
     * runs.
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function register(): void
    {
        // `Audit::class` is a compile-time string that autoloads nothing; the
        // event name is a literal for the same reason (a class-constant read
        // would autoload the class). The closure's parameter type is resolved
        // lazily at call time — only ever when Auth Kit fired the event, so
        // Auth Kit is present by then.
        Event::on(
            \craftpulse\authkit\services\Audit::class,
            self::AUTH_KIT_EVENT_AFTER_RECORD,
            static function(\craftpulse\authkit\events\AuditRecordEvent $event): void {
                self::relay($event->event);
            },
        );
    }

    /**
     * Maps one Auth Kit `AuthEvent` onto an {@see AuditEvent} and records it on
     * the audit bus. Events whose name is outside Auth Kit's frozen vocabulary
     * are dropped with a warning — see the class docblock.
     *
     * @param \craftpulse\authkit\audit\AuthEvent $authEvent the Auth Kit event
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function relay(\craftpulse\authkit\audit\AuthEvent $authEvent): void
    {
        if (!isset(self::vocabulary()[$authEvent->name])) {
            \Craft::warning(
                "AuthKitBridge dropped event \"{$authEvent->name}\" from emitter \"{$authEvent->emitter}\": " .
                'the name is not part of Auth Kit\'s frozen AuthEvent vocabulary. Non-auth events must be ' .
                'emitted as native audit-kit AuditEvents on the bus, not ridden through the AuthEvent contract.',
                __METHOD__,
            );

            return;
        }

        AuditKit::$plugin->getBus()->record(new AuditEvent(
            name: $authEvent->name,
            category: self::CATEGORY_AUTH,
            emitter: $authEvent->emitter,
            outcome: $authEvent->outcome,
            actorId: $authEvent->actorId,
            targetType: $authEvent->userId !== null ? self::TARGET_TYPE_USER : null,
            targetId: $authEvent->userId,
            details: $authEvent->details,
        ));
    }

    /**
     * Resets the memoized vocabulary. Test seam.
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function resetVocabulary(): void
    {
        self::$vocabulary = null;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns Auth Kit's frozen event-name vocabulary: the values of every
     * public string constant declared on the installed `AuthEvent` class,
     * excluding the `OUTCOME_*` value constants. Reflection tracks Auth Kit's
     * additive-minor vocabulary growth automatically. Only ever called from
     * [[relay()]], so Auth Kit is guaranteed present.
     *
     * @return array<string, true> event names as keys
     * @throws \ReflectionException never in practice — the class is loaded by the time relay() runs
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private static function vocabulary(): array
    {
        if (self::$vocabulary !== null) {
            return self::$vocabulary;
        }

        $vocabulary = [];
        $reflection = new \ReflectionClass(\craftpulse\authkit\audit\AuthEvent::class);

        foreach ($reflection->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
            $value = $constant->getValue();

            if (is_string($value) && !str_starts_with($constant->getName(), self::OUTCOME_CONST_PREFIX)) {
                $vocabulary[$value] = true;
            }
        }

        return self::$vocabulary = $vocabulary;
    }
}
