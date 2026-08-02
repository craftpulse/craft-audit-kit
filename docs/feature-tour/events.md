# Events

Audit Kit provides a collection of events for extending its functionality. Modules and plugins can register event listeners, typically in their `init()` methods, to modify Audit Kit's behavior.

The events fall into two groups. Registration events are how a consuming plugin contributes its own sinks and event types, and every consumer uses them. Lifecycle events let a consumer observe the engine's internals.

## Registration Events

### The `registerAuditSinks` event

The event that is triggered when the bus assembles its sink registry, letting a consuming plugin contribute its own `AuditSinkInterface` implementations. It fires once, on first access to the registry.

Append to `$event->sinks` rather than replacing the array. Another recorder may already have registered, and overwriting silently disables it.

```php
use craftpulse\auditkit\events\RegisterAuditSinksEvent;
use craftpulse\auditkit\services\Bus;
use yii\base\Event;

Event::on(Bus::class, Bus::EVENT_REGISTER_AUDIT_SINKS, function(RegisterAuditSinksEvent $event) {
    $sinks = $event->sinks;

    $event->sinks[] = new \mynamespace\sinks\MyRecorderSink();
    // ...
});
```

### The `registerAuditEvents` event

The event that is triggered when the event-type registry is first assembled, letting a consuming plugin contribute the `AuditEventType` definitions for every event type it emits. It fires once, on first access to the registry.

An event type that was never registered here is dropped fail-closed at record time, so this registration is required rather than optional.

```php
use craftpulse\auditkit\audit\AuditEventType;
use craftpulse\auditkit\events\RegisterAuditEventsEvent;
use craftpulse\auditkit\services\EventTypes;
use yii\base\Event;

Event::on(EventTypes::class, EventTypes::EVENT_REGISTER_AUDIT_EVENTS, function(RegisterAuditEventsEvent $event) {
    $eventTypes = $event->eventTypes;

    $event->eventTypes[] = new AuditEventType(
        name: 'content.element.saved',
        category: 'content',
        label: 'Element saved',
        allowedDetailKeys: ['elementType', 'sectionHandle', 'isNew'],
    );
    // ...
});
```

Two registrations sharing a `name` do not both survive: the registry is keyed by event name, so the last one registered wins. Namespace your event names with your plugin's own prefix to avoid colliding with another consumer.

## Chain Lifecycle Events

### The `chainRotated` event

The event that is triggered after `Pruner::prune()` deletes at least one row and leaves at least one row behind. It carries the boundary between what was deleted and what survived.

It does not fire when the prune deleted nothing, or when it emptied the table entirely. In the second case there is no surviving row to anchor a boundary to.

```php
use craftpulse\auditkit\engine\Pruner;
use craftpulse\auditkit\events\ChainRotatedEvent;
use yii\base\Event;

Event::on(Pruner::class, Pruner::EVENT_CHAIN_ROTATED, function(ChainRotatedEvent $event) {
    $startId = $event->startId;
    $startRowHash = $event->startRowHash;
    $endId = $event->endId;
    $endRowHash = $event->endRowHash;
    $rotatedAt = $event->rotatedAt;
    // ...
});
```

| Property | Description |
|---|---|
| `startId` | The id of the new first surviving row, which is the new chain start. |
| `startRowHash` | The `rowHash` of the new first surviving row. |
| `endId` | The highest id deleted in this prune. |
| `endRowHash` | The `rowHash` of the highest deleted row, which is the surviving head's `previousHash`. |
| `rotatedAt` | The UTC timestamp at which the rotation occurred. |

`endRowHash` is the rotation boundary, and it is not the same value as `startRowHash`. Under an id-boundary prune the two are never equal: one is the hash of the last row that left, the other is the hash of the first row that stayed.

Persist the boundary somewhere durable. It is the evidence that the chain was continuous across the prune, and without it a later full walk can only fall back on the retention-window tolerance in `ChainVerifier`. Recording rotations turns "this chain was probably legitimately pruned" into "this chain was pruned at this time, from this hash to this hash".

## Auth Kit events

Audit Kit does not fire an event for bridged authentication events. The bridge records them straight onto the bus, so a sink receives them the same way it receives everything else. See [The Auth Kit bridge](auth-kit-bridge.md).
