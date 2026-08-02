# The Auth Kit bridge

With [Auth Kit](https://github.com/craftpulse/craft-auth-kit) installed, `AuthKitBridge` relays Auth Kit's authentication events onto the audit bus. A recorder then wires one seam: register a single sink on the bus and receive auth, content, system, and every other event through it, instead of also registering a second listener on Auth Kit's own sink registry.

You do not wire the bridge. It is registered during module registration, so it is live as soon as any consumer has called `AuditKit::register()`.

## No hard dependency

Audit Kit suggests Auth Kit, never requires it.

The listener is wired with a compile-time class string, which autoloads nothing. With Auth Kit absent the target class never loads, its event never fires, and the closure never runs. There is no fatal, no `class_exists()` guard to maintain, and nothing for a consumer to check.

This is why it matters to a consumer: your plugin can depend on Audit Kit and record authentication events when Auth Kit is present, without requiring Auth Kit yourself and without branching on its presence.

## What gets bridged

Auth Kit's `AuthEvent` supersets cleanly into `AuditEvent`:

| `AuthEvent` | `AuditEvent` |
|---|---|
| `name` | `name`, unchanged. |
| `emitter` | `emitter`, unchanged. |
| `outcome` | `outcome`, unchanged. Auth Kit's two values are a subset of the kit's three. |
| `actorId` | `actorId`, unchanged. |
| `details` | `details`, unchanged. |
| `userId` | `targetId`, with `targetType` set to `user` when the id is present. |
| (none) | `category`, always the literal `auth`. |

Every bridged event lands under `category: auth`. A sink can filter on that alone to separate authentication from everything else.

## Only Auth Kit's frozen vocabulary is relayed

The bridge relays an event only when its `name` is part of Auth Kit's frozen `AuthEvent` vocabulary, meaning a declared public name constant on the installed `AuthEvent` class. Anything else is dropped with a warning in the log.

This is deliberate and worth understanding before you rely on the bridge. `AuthEvent` does not validate names at construction, so a third party can push a foreign name through Auth Kit's sink registry. Relaying those blindly would stamp non-auth events into recorders' append-only chains under `category: auth`, and an append-only chain cannot be corrected later: the misclassification would be permanent.

The vocabulary is read from the installed `AuthEvent` class at runtime, so vocabulary growth in an Auth Kit minor release flows through with no Audit Kit release.

The practical consequence for a consumer: **do not ride your own events through the `AuthEvent` contract to get them onto the audit bus.** They will be dropped. Emit them as native `AuditEvent`s on the bus instead, which is one line and gets you the correct category.

If you see `AuthKitBridge dropped event` warnings in your logs, that is what happened, and the fix is in the emitting plugin rather than in the bridge.

## Requirements

The bridge is written against Auth Kit's 1.5.0 public contract, and Audit Kit suggests `craftpulse/craft-auth-kit` at `^1.6`.
