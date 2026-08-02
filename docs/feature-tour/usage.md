# Usage

Let's run through an example from start to finish: a plugin that records an auditable fact and persists it on a tamper-evident chain.

## The flow

Auditing one fact, end to end, is six steps:

1. Declare the event types your plugin emits, and which `details` keys each one may carry.
2. Build an event value object at the moment the fact happens.
3. Hand it to a dispatch bus.
4. A sink receives it and decides whether to persist it.
5. The sink writes a row whose hash links to the row before it.
6. Later, someone verifies, prunes, exports, forwards, or anchors that chain.

Audit Kit takes care of steps 1, 2, 3, and the hashing half of 5, and gives you primitives for 6. It will be up to your plugin to nominate the capture points, own the database table, and write the sink that turns an event into a row.

That split is the whole design. The kit owns no tables and no schema, because a chain of authentication attempts and a chain of content edits need different columns. What they must share is the event shape, the dispatch seam, and the byte format of the hash, and those are exactly what the kit owns.

## 1. Register your event types

Before we dive in, we'll need to declare what our plugin emits. An event type that was never registered is dropped at record time, so this is not optional bookkeeping.

```php
use craftpulse\auditkit\audit\AuditEventType;
use craftpulse\auditkit\events\RegisterAuditEventsEvent;
use craftpulse\auditkit\services\EventTypes;
use yii\base\Event;

Event::on(EventTypes::class, EventTypes::EVENT_REGISTER_AUDIT_EVENTS, function(RegisterAuditEventsEvent $event) {
    $event->eventTypes[] = new AuditEventType(
        name: 'content.element.saved',
        category: 'content',
        label: 'Element saved',
        allowedDetailKeys: ['elementType', 'sectionHandle', 'isNew'],
    );
});
```

`allowedDetailKeys` is the codified privacy contract for this event type. Anything outside it is stripped before a row lands, so an emitter that starts passing an extra key cannot widen what gets persisted without a matching registration change.

Register in your plugin's `init()`, alongside your other event wiring. See [Events](events.md) for the full registration reference.

## 2. Emit an event

At the point the fact happens, build an `AuditEvent` and record it:

```php
use craftpulse\auditkit\audit\AuditEvent;
use craftpulse\auditkit\AuditKit;

AuditKit::getInstance()->getBus()->record(new AuditEvent(
    name: 'content.element.saved',
    category: 'content',
    emitter: 'my-plugin',
    outcome: AuditEvent::OUTCOME_SUCCESS,
    actorId: Craft::$app->getUser()->getId(),
    targetType: 'entry',
    targetId: $entry->id,
    targetUid: $entry->uid,
    details: ['sectionHandle' => $entry->getSection()->handle, 'isNew' => $isNew],
    eventSiteId: $entry->siteId,
));
```

Use named arguments. The constructor takes eleven parameters and eight of them are optional, so positional calls are unreadable and break loudly if the contract ever grows.

Recording is cheap when nothing is listening. With no sink registered, `record()` is a no-op, which means a plugin can emit unconditionally without checking whether a recorder is installed.

See [Audit events](audit-events.md) for the full contract, including what the value object refuses to accept.

## 3. Register a sink

Emitting alone persists nothing. A recorder ships an adapter implementing `AuditSinkInterface` and registers it on the bus:

```php
use craftpulse\auditkit\audit\AuditEvent;
use craftpulse\auditkit\audit\AuditSinkInterface;

class MyRecorderSink implements AuditSinkInterface
{
    public function handle(AuditEvent $event): void
    {
        $type = AuditKit::getInstance()->getEventTypes()->getEventType($event->name);

        if ($type === null) {
            return;
        }

        $details = AuditKit::getInstance()->getEventTypes()->sanitizeDetails($type, $event->details);

        // Persist the row, see step 4
    }
}
```

The fail-closed check and the allowlist strip both live in the sink, not in the bus. The bus is deliberately dumb: it dispatches and isolates, and makes no policy decisions.

See [The bus](the-bus.md) for registration and the rules a sink must honour.

## 4. Write it onto a chain

Persisting through `ChainWriter` is what makes the row tamper-evident. You supply the table, the exact payload to hash, and a closure that does the insert:

```php
use craftpulse\auditkit\engine\ChainWriter;

$payload = [
    'eventName' => $event->name,
    'category' => $type->category,
    'emitter' => $event->emitter,
    'outcome' => $event->outcome,
    'actorId' => $event->actorId,
    'details' => $details,
];

$id = (new ChainWriter())->write(
    Craft::$app->getDb(),
    '{{%myplugin_audit}}',
    $payload,
    function(string $previousHash, string $rowHash) use ($payload): int {
        // Insert the row, then return its id
    },
);
```

The writer reads the chain tail under a row lock, computes `sha256(canonical(payload) . previousHash)`, and hands both hashes to your closure inside the transaction. Retries under lock contention are handled for you.

See [The chain engine](chain-engine.md) for the payload rules, which are strict and easy to get wrong.

## 5. Everything after the write

Once rows are on a chain, the rest of the kit operates on them:

- [The chain engine](chain-engine.md) verifies a chain and prunes it without punching a hole in it.
- [Export](export.md) streams it to CSV, JSONL, JSON, HTML, or PDF.
- [SIEM forwarding](siem.md) ships records off the box over syslog, signed webhooks, HTTP, or S3.
- [Anchoring](anchoring.md) commits a Merkle root to an external timestamping authority or an Object Lock bucket, so the chain's existence at a point in time is provable to someone who does not trust your server.

None of these are wired up for you. They are primitives your plugin schedules from its own queue jobs, console commands, and utilities.

## Summary

So what does Audit Kit do to help with this overall process, rather than doing it yourself?

- Provides one neutral event contract every CraftPulse plugin already speaks, so a recorder written once receives events from all of them.
- Provides a dispatch bus with per-sink isolation, so a failing recorder can neither block the emitting request nor starve the other sinks.
- Provides a fail-closed event-type registry, so an unregistered event or an unexpected `details` key cannot write an unconstrained payload.
- Provides a byte-deterministic canonicalizer, so two installs hashing the same payload produce the same bytes, and a chain stays verifiable across releases.
- Provides a serialized chain writer that survives concurrent load, with bounded retries and full-jitter backoff instead of lost events.
- Provides chain verification with first-divergence reporting and retention-boundary tolerance, so a legitimately pruned chain does not read as tampering.
- Provides streaming export, SIEM delivery, and external anchoring, so a compliance surface is assembly rather than construction.
- Provides a dependency-free verifier an auditor can run against an export without Craft, Composer, or access to your database.
