# Audit events

`AuditEvent` is the neutral value object an emitter hands the bus to describe one auditable fact. A sink receives it and decides what to persist.

```php
use craftpulse\auditkit\audit\AuditEvent;

$event = new AuditEvent(
    name: 'login.magic_link',
    category: 'auth',
    emitter: 'warp',
    outcome: AuditEvent::OUTCOME_SUCCESS,
    actorId: 42,
    targetType: 'user',
    targetId: 42,
);
```

## The contract is frozen

The `AuditEvent` shape is frozen at 1.0.0. The readonly properties and the constructor signature are the contract, and any change to that shape is a major version bump. You can build against it without defensive checks, and a recorder written against 1.0.0 keeps receiving valid events from a 1.x kit.

What is additive, and ships in minor releases, is vocabulary: new `name` values, new `category` values, new outcome values. That is why the contract asks recorders to ignore names and categories they do not recognise. A sink that throws on an unfamiliar name will break the first time an emitter is upgraded ahead of it.

## Properties

| Property | Description |
|---|---|
| `name` | The event machine-key, for example `login.magic_link` or `content.element.saved`. |
| `category` | The coarse grouping, for example `auth`, `content`, `system`, `permissions`, or `plugins`. |
| `emitter` | The handle of the plugin that emitted the event. |
| `outcome` | One of the `OUTCOME_*` constants. |
| `actorId` | The acting user's id, or `null` for a system or self event. |
| `targetType` | The type or handle of the thing acted upon, or `null`. An auth subject is `user`. |
| `targetId` | The id of the thing acted upon, or `null`. |
| `targetUid` | The uid of the element acted upon, or `null`. |
| `details` | Scalar-only, non-PII context, as a string-keyed array. |
| `diff` | A before-to-after change diff, or `null`. |
| `eventSiteId` | The site the audited action applied to, or `null`. |

Every property is `readonly`. There is no setter, no `fromArray()`, and no partial construction: an event is valid the moment it exists or it never exists at all.

## Outcomes

| Constant | Description |
|---|---|
| `AuditEvent::OUTCOME_SUCCESS` | The event succeeded. This is the default. |
| `AuditEvent::OUTCOME_FAILURE` | The event failed. |
| `AuditEvent::OUTCOME_WARNING` | The event is a non-fatal warning, such as an account lockout. |

Passing anything else throws `InvalidArgumentException` at construction.

## What the value object refuses

Two rules are enforced in the constructor rather than left to a sink, because a payload that has already been persisted is too late to fix.

**Unknown outcomes throw.** The three-value vocabulary is closed at the kit level.

**Non-scalar `details` values throw.** Every value in `details` must be a scalar or `null`. Nest an array or an object and construction fails with the offending key named.

`details` must also carry no PII: no email addresses, no raw IPs, no raw user agents. That part is a contract you honour, not one the constructor can check for you. If you need to record who or where without recording the identity, hash it first with [`ContextCapturer`](chain-engine.md#contextcapturer).

`name` is deliberately not validated against a known set. Forward compatibility requires that a newer emitter's name survive an older kit, so the value object accepts any string and the fail-closed check happens at the registry instead.

## Event types

An `AuditEventType` declares one event type: where it lands, what to call it, and precisely which `details` keys a recorder may persist.

```php
use craftpulse\auditkit\audit\AuditEventType;

new AuditEventType(
    name: 'content.element.saved',
    category: 'content',
    label: 'Element saved',
    allowedDetailKeys: ['elementType', 'sectionHandle', 'isNew'],
);
```

| Property | Description |
|---|---|
| `name` | The event machine-key this definition applies to. |
| `category` | The coarse grouping, authoritative over the category an emitter declared on the event itself. |
| `label` | The human-readable label for control panel surfaces. |
| `allowedDetailKeys` | The `details` keys a recorder may persist. Defaults to an empty array, which strips everything. |

The type's `category` wins over the event's. An emitter can mislabel its own event; the registered definition is the auditable answer.

`allowedDetailKeys` defaulting to empty is deliberate. Registering a type without thinking about its payload gives you an event with no details, never an event with unconstrained details.

`category` is a plain string rather than an enum because the kit does not own the estate's category vocabulary. Register whatever strings your own surfaces group on.

## The registry

`EventTypes` holds the registered definitions and applies the allowlist. Reach it through `AuditKit::getInstance()->getEventTypes()`.

```php
use craftpulse\auditkit\AuditKit;

$registry = AuditKit::getInstance()->getEventTypes();

// Look one event type up by machine-key, or get null if it was never registered
$type = $registry->getEventType('content.element.saved');

// Get every registered event type, keyed by machine-key
$all = $registry->getEventTypes();

// Strip the details keys this type does not permit
$details = $registry->sanitizeDetails($type, $event->details);

// Replace the registry outright, for tests and explicit wiring
$registry->setEventTypes([$type]);
```

The registry is assembled lazily from the registration event on first access, so registering from any plugin's `init()` is early enough.

`sanitizeDetails()` returns `null` rather than an empty array when nothing survives the strip, so a column holding a JSON payload stays null instead of holding `{}`.

## Applying the registry in a sink

The registry does not enforce itself. A sink consults it, and that is where the fail-closed behaviour actually happens:

```php
$type = AuditKit::getInstance()->getEventTypes()->getEventType($event->name);

if ($type === null) {
    return;
}

$details = AuditKit::getInstance()->getEventTypes()->sanitizeDetails($type, $event->details);
```

Dropping the event when `getEventType()` returns `null` is the fail-closed half: a typo'd or unregistered name must never write an unconstrained payload. Stripping with `sanitizeDetails()` is the privacy half.

A sink that skips the null check will happily persist events nobody declared. A sink that skips `sanitizeDetails()` will persist whatever an emitter passed. Both are easy to leave out and neither fails loudly, so wire them together.
