# The bus

`Bus` is the neutral dispatch seam. An emitter records an `AuditEvent` on it, and the bus fans that event out to every registered sink.

```php
use craftpulse\auditkit\AuditKit;

AuditKit::getInstance()->getBus()->record($auditEvent);
```

That is the whole emitting API. An emitter never knows which recorders are installed, and a recorder never knows which plugins emit.

## Recording

| Method | Description |
|---|---|
| `record(AuditEvent $event): void` | Dispatches one event to every registered sink, in registration order. |
| `getSinks(): array` | Returns the registered sinks, assembling them from the registration event on first access. |
| `setSinks(array $sinks): void` | Replaces the registered sinks, for tests and explicit wiring. |

Recording is synchronous. `record()` returns once every sink has been given the event, so a slow sink is time spent in the emitting request. Move expensive work (network delivery, PDF generation) into a queue job from inside your sink rather than doing it in `handle()`.

Recording is also defensive. Each sink is invoked inside its own try/catch, so a sink that throws is logged and skipped: it can neither block the emitting flow nor prevent the sinks after it from running.

With no sinks registered, `record()` is a cheap no-op. That is what lets a plugin emit unconditionally without checking whether anyone is recording.

## The bus makes no policy decisions

The bus performs no allowlist stripping and no fail-closed gating. It does not consult the event-type registry, and it does not filter by name, category, or emitter. It dispatches and it isolates.

That work belongs in your sink, where you have the context to decide what your plugin should persist. See [Audit events](audit-events.md#applying-the-registry-in-a-sink) for the two calls that implement it.

## Writing a sink

A recorder ships a thin adapter implementing `AuditSinkInterface`. The interface is deliberately tiny:

```php
use craftpulse\auditkit\audit\AuditEvent;
use craftpulse\auditkit\audit\AuditSinkInterface;

class MyRecorderSink implements AuditSinkInterface
{
    public function handle(AuditEvent $event): void
    {
        // Persist or forward the event
    }
}
```

| Method | Description |
|---|---|
| `handle(AuditEvent $event): void` | Handles one recorded event, persisting or forwarding it as the sink sees fit. |

Two rules bind every sink.

**Ignore names and categories you do not recognise, silently.** The kit's vocabulary grows in minor releases, so a sink will receive names newer than the vocabulary it was written against. Return early; do not throw, and do not log an error for every unfamiliar event.

**Fail softly.** The bus wraps each sink in its own try/catch, so a throwing sink is isolated. Do not rely on that backstop: log and return rather than letting an exception escape. The backstop exists so one broken recorder cannot take down an install, not as an error-handling strategy.

Treat any change to `AuditSinkInterface` as a major version bump.

## Registering a sink

Register your sink through the `EVENT_REGISTER_AUDIT_SINKS` event, from your plugin's `init()`:

```php
use craftpulse\auditkit\events\RegisterAuditSinksEvent;
use craftpulse\auditkit\services\Bus;
use yii\base\Event;

Event::on(Bus::class, Bus::EVENT_REGISTER_AUDIT_SINKS, function(RegisterAuditSinksEvent $event) {
    $event->sinks[] = new MyRecorderSink();
});
```

The registry is assembled lazily on first use, so registering in `init()` is early enough. Append to `$event->sinks` rather than replacing it: another recorder may already have registered, and overwriting the array silently disables it.

`setSinks()` exists for tests and explicit wiring. It replaces the registry outright and bypasses the event, so do not call it from plugin code on a live install.

## Multiple sinks

Every registered sink receives every event. On an install with two recorders, both see the same `AuditEvent`, and each decides independently what to keep. There is no routing, no filtering layer, and no way for one sink to consume an event so another does not see it.

That is the intended shape. A recorder that persists content events and a forwarder that ships auth events to a SIEM are both just sinks that ignore most of what they receive.
