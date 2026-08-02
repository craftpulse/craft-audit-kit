# The chain engine

The engine turns rows in your table into a tamper-evident chain: each row's hash covers its own payload and the hash of the row before it, so altering or removing any row invalidates every hash after it.

The engine classes are plain classes, not services. You instantiate them directly with your own table, payload, and storage closure. The kit owns no tables, so it can only hash what you hand it.

## Canonicalizer

`Canonicalizer` is the byte-deterministic JSON encoder every hash in the kit runs through. Two installs encoding the same payload produce identical bytes, which is what makes a chain verifiable somewhere other than where it was written.

| Method | Description |
|---|---|
| `Canonicalizer::canonicalize(array $payload): string` | Returns the canonical JSON encoding of a payload, which is the exact input to the chain SHA-256. |
| `Canonicalizer::sortRecursive(array $value): array` | Returns the payload with string keys sorted at every depth, preserving list order. |

| Constant | Description |
|---|---|
| `Canonicalizer::GENESIS_PREVIOUS_HASH` | The 64-zero sentinel used as `previousHash` for the first row in a chain. |
| `Canonicalizer::CANONICAL_DATE_FORMAT` | The UTC date format (`Y-m-d\TH:i:s\Z`) payload dates must be rendered in. |

The encoding recipe is frozen and underpins live production chains:

- String keys are sorted alphabetically with `SORT_STRING`, recursively.
- List arrays keep their order and are never sorted.
- The flags are `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
- `null` stays `null`, booleans stay `true` and `false`, integers stay bare, and there is no whitespace.

Render dates with `CANONICAL_DATE_FORMAT` before putting them in a payload. It drops sub-second precision deliberately, because MySQL `DATETIME` columns do too, and a payload that round-trips through the database must hash the same on the way back out.

## ChainWriter

`ChainWriter` performs the serialized, in-transaction chain write.

```php
use craftpulse\auditkit\engine\ChainWriter;

$writer = new ChainWriter();

$id = $writer->write(
    Craft::$app->getDb(),
    '{{%myplugin_audit}}',
    $canonicalPayload,
    function(string $previousHash, string $rowHash) use ($canonicalPayload): int {
        Db::insert('{{%myplugin_audit}}', $canonicalPayload + [
            'previousHash' => $previousHash,
            'rowHash' => $rowHash,
        ]);

        return (int)Craft::$app->getDb()->getLastInsertID();
    },
);
```

`write(Connection $db, string $table, array $canonicalPayload, callable $persist): mixed` returns whatever your closure returns, which is normally the new row's primary key.

Inside a transaction, the writer reads the current chain tail with `SELECT rowHash ... ORDER BY id DESC LIMIT 1 FOR UPDATE`, falling back to `GENESIS_PREVIOUS_HASH` on an empty table, then computes:

```
rowHash = sha256(canonicalize(payload) . previousHash)
```

and calls `$persist($previousHash, $rowHash)`. The row lock is what serializes concurrent writers: two requests cannot both read the same tail and both link to it.

### The payload rules

The writer hashes exactly the array you hand it. It adds nothing, removes nothing, and reorders nothing. Two consequences matter, and both are easy to get wrong:

**Exclude anything mutable.** A column that can change after insert (a foreign key that gets repointed, a status that gets updated, an enrichment written by a later job) must not be in the canonical payload, or the row stops verifying the moment it changes. Store those columns; just do not hash them.

**Rebuild the payload identically at verification time.** `ChainVerifier` re-hashes using a closure you supply, and it must produce the same key set with the same value types. An integer that comes back from the database as a string will not hash the same.

### The persist closure

The closure receives `(string $previousHash, string $rowHash)` and must do the insert. Two requirements:

- It runs inside the writer's transaction. Do not open your own.
- It must be safe to call more than once. A retried attempt calls it again, in a fresh transaction, with freshly computed hashes. Nothing from a rolled-back attempt is visible to the next one, so a plain insert is fine; caching or side effects outside the database are not.

### Retries and contention

Under concurrent load, MySQL will abort some of these transactions. The writer retries them.

A retryable failure is a deadlock (`1213`, `SQLSTATE 40001`) or a lock-wait timeout (`1205`). Anything else is rethrown immediately, with no retry. Each retry re-reads the tail and recomputes `previousHash` and `rowHash` from scratch in a new transaction, so nothing is carried across attempts. Backoff is full-jitter exponential, starting at 10 milliseconds and capping at 200.

```php
new ChainWriter(maxAttempts: 5);
```

The default retry budget is 5 attempts (`ChainWriter::DEFAULT_MAX_ATTEMPTS`). Raise it on a chain with heavy concurrent writes, at the cost of holding the request longer under contention. Lower it only if you have a queue to fall back on.

When the budget is exhausted, `write()` throws `ChainWriteRetriesExhaustedException`, which carries the attempt count on `$e->attempts` and the last serialization failure as its previous exception.

Catch that exception specifically and requeue the write. Catching generic `\Throwable` and logging it recreates exactly the silent-loss failure mode the retries exist to prevent.

The constructor's second argument is a sleeper closure, so tests can run the retry path without real sleeps. Leave it alone in production code.

## ChainVerifier

`ChainVerifier` walks a chain and stops at the first divergence.

```php
use craftpulse\auditkit\engine\ChainVerifier;

$result = (new ChainVerifier())->verify(
    $rows,
    fn(array $row): array => [
        'eventName' => $row['eventName'],
        'category' => $row['category'],
        'emitter' => $row['emitter'],
        'outcome' => $row['outcome'],
        'actorId' => $row['actorId'] === null ? null : (int)$row['actorId'],
    ],
    isFullWalk: true,
    retentionDays: 365,
);

if (!$result->isValid()) {
    // $result->breakId, $result->breakReason, $result->expectedHash, $result->storedHash
}
```

`verify(iterable $rows, callable $rebuildPayload, bool $isFullWalk = true, int $retentionDays = 365): ChainVerifierResult`

Pass rows in id-ascending order. Each must carry at least `id`, `previousHash`, `rowHash`, and `dateCreated`. `$rebuildPayload` reconstructs the exact canonical key set that was hashed at write time, which is why the write-side and verify-side payload shapes must be defined together.

The walk stops at the first bad row. It does not continue and count further failures, because after a break every subsequent hash is meaningless anyway.

Set `isFullWalk` to `false` when verifying a bounded slice rather than the whole chain. On a full walk the first row must either be genesis or an acceptable retention boundary; on a bounded walk the first row's stored `previousHash` is trusted as the starting anchor.

`retentionDays` should match the retention you actually prune at. It exists so a chain whose head was legitimately pruned does not read as tampering: a non-genesis first row is accepted if it is old enough to sit beyond the retention cutoff, with a 24 hour safety margin (`RETENTION_BOUNDARY_SAFETY_MARGIN_SECONDS`) to absorb clock skew and prune scheduling drift.

### ChainVerifierResult

| Property | Description |
|---|---|
| `exitCode` | One of the `ChainVerifier::EXIT_*` constants. |
| `totalRows` | The number of rows walked, including the offending row on a break. |
| `verifiedRows` | The number of rows that verified before any break. |
| `breakId` | The id of the offending row, or `null` on a clean pass. |
| `breakReason` | Either `previousHash mismatch` or `rowHash mismatch`, or `null`. |
| `expectedHash` | The hash recomputed at the break, or `null`. |
| `storedHash` | The hash found in the database at the break, or `null`. |

| Method | Description |
|---|---|
| `isValid(): bool` | Returns whether the walk completed without a break. |

The two break reasons mean different things. `rowHash mismatch` means that row's own contents changed. `previousHash mismatch` means a row was removed or reordered, or a row was inserted between two others.

### Exit codes

| Constant | Description |
|---|---|
| `ChainVerifier::EXIT_OK` (`0`) | The chain is valid. |
| `ChainVerifier::EXIT_CHAIN_BREAK` (`1`) | A row diverged from the chain. |
| `ChainVerifier::EXIT_UNREADABLE` (`2`) | The input could not be read: schema drift, a malformed payload, or a usage error. |

`verify()` itself only ever returns `EXIT_OK` or `EXIT_CHAIN_BREAK`. `EXIT_UNREADABLE` is published for the console command you wrap it in, so a verification job can be routed on its exit status: a chain break is a security page, an unreadable chain is an operations page. Keep them distinct. Collapsing both into a non-zero exit means every schema mistake wakes the wrong person.

## ContextCapturer

`ContextCapturer` produces HMAC-hashed identifiers for the request context, so a chain can record who and where without storing PII.

```php
use craftpulse\auditkit\engine\ContextCapturer;

$capturer = new ContextCapturer('$MY_PLUGIN_PII_KEY');

[$ipHash, $userAgentHash] = $capturer->requestFingerprint();
$actorHash = $capturer->hashUserIdentifier($userId);
```

| Method | Description |
|---|---|
| `requestFingerprint(): array` | Returns the hashed IP and hashed user agent as a two-element array, or two nulls in a console request. |
| `hashUserIdentifier(int $userId): ?string` | Returns the hash of a user's email, or `null` when the user is gone or has no email. |
| `hashValue(string $value): string` | Returns the HMAC-SHA-256 of any value under the resolved PII key. |
| `resolvePiiKey(): string` | Returns the HMAC key currently in use. |
| `geo(string $ip): ?GeoResult` | Resolves an IP through the injected geo provider, or returns `null`. |

Hashing rather than storing means an auditor can still answer "was this the same actor as that one" and "did these two events come from the same address", without the chain holding an email or an IP.

`hashUserIdentifier()` hashes the email rather than the user id on purpose: the hash stays stable and comparable after the user row is deleted, which is exactly when an audit trail matters most.

### The PII key

The kit hardcodes no environment variable name. You pass your own key setting into the constructor, and each consuming plugin keeps its own:

```php
new ContextCapturer('$MY_PLUGIN_PII_KEY');
```

The value is resolved through `Craft::parseEnv()`, so an environment variable reference works and a literal string also works. When no key is configured, the fallback is Craft's `securityKey`.

Use a dedicated key rather than relying on the fallback. Rotating `securityKey` for an unrelated reason would silently break every comparison in your chain, because the same input would start hashing differently. A dedicated key is one you can decide never to rotate.

### Geo lookups

Geo resolution is an injected seam. The kit ships `NullGeoProvider`, which always returns `null`, as the default, so `ContextCapturer` never has to branch on whether a provider is present.

```php
use craftpulse\auditkit\engine\geo\GeoProviderInterface;
use craftpulse\auditkit\engine\geo\GeoResult;

class MyGeoProvider implements GeoProviderInterface
{
    public function lookup(string $ip): ?GeoResult
    {
        return new GeoResult(country: 'Belgium', countryCode: 'BE', region: 'Antwerp');
    }
}
```

`lookup()` must never throw. Return `null` for a missing database, an unknown IP, a private or reserved address, a malformed address, or any internal failure. A geo lookup that throws would take down an audit write, which is a far worse outcome than an unresolved country.

`GeoResult` carries `country`, `countryCode`, and `region`, all nullable. Persist only `countryCode` and `region`; `country` is for display. There is deliberately no city field and no raw IP.

## Pruner

`Pruner` removes rows past a retention window without breaking the chain.

```php
use craftpulse\auditkit\engine\Pruner;

$pruner = new Pruner();

$deleted = $pruner->prune(
    Craft::$app->getDb(),
    '{{%myplugin_audit}}',
    365,
    function(array $ids): int {
        return Db::delete('{{%myplugin_audit}}', ['id' => $ids]);
    },
);
```

`prune(Connection $db, string $table, int $daysToKeep, callable $deleteUpToId): int` returns the number of rows deleted.

The closure receives the resolved list of ids and returns how many it deleted. It owns the delete semantics, which is what lets a consumer whose rows are elements do a cascading element delete while a consumer with plain records does a straight `DELETE`.

### Why it prunes by id, not by date

The pruner resolves the highest id whose `dateCreated` is older than the threshold, then selects everything with an id at or below that. It never deletes by date directly.

The chain links in id order, and wall-clock time does not always agree with id order: an NTP correction, a DST transition, or an out-of-order backfill can leave a row with an older `dateCreated` than a lower-id neighbour. Deleting by `dateCreated` would then punch a hole in the middle of the chain, and the verifier would correctly report that as tampering.

Pruning by id boundary guarantees a contiguous prefix removal. The surviving chain always starts at a real row whose `previousHash` points at the last row that left.

### The rotation event

After a prune that deleted at least one row and left at least one behind, `Pruner` fires `EVENT_CHAIN_ROTATED`. Record the boundary it carries: it is what lets a later verification run prove the chain is continuous across the prune. See [Events](events.md#the-chainrotated-event).

No event fires when the prune deleted nothing, or when it emptied the table entirely.
