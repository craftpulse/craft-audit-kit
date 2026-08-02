# SIEM forwarding

Audit Kit ships the delivery primitives a consuming plugin needs to ship audit records off the box: syslog framing over TLS, signed webhooks, an HTTP JSON forwarder, an S3 forwarder, and a shared circuit breaker.

These are plain classes with no registration event and no shared base. The kit does not schedule delivery, retry it, or own a queue: you decide when to forward, from a queue job or a console command, and the kit gives you the wire formats and the failure signals.

## Syslog over TLS

`SyslogFramer` builds an RFC 5424 frame and ships it over a TLS socket.

```php
use craftpulse\auditkit\siem\SyslogFramer;

$framer = new SyslogFramer();
$frame = $framer->frame($canonicalJson, 'audit-kit', 'audit-log');
$framer->send('siem.example.com', 6514, $frame);
```

| Method | Description |
|---|---|
| `frame(string $body, string $appName = 'audit-kit', string $msgId = 'audit-log'): string` | Wraps a pre-canonicalised body in an RFC 5424 frame and returns it. |
| `send(string $host, int $port, string $frame, bool $verifyCert = true, ?string $caBundlePath = null): void` | Opens a `tls://` socket to the collector and writes the frame. |

The frame shape is `<PRI>1 TIMESTAMP HOSTNAME APP-NAME PROCID MSGID - MSG`, with a literal `-` for structured data. The priority is fixed at 133, which is facility `local0` and severity `notice`. The timestamp is UTC.

`send()` is plaintext-refusing by construction: the only transport it opens is `tls://`. Certificate verification is on by default. Turn it off only against a collector using a self-signed certificate you control, and prefer passing `$caBundlePath` instead, which accepts a Craft environment variable and pins your own CA. Connect and write both time out after 5 seconds, and a partial write throws rather than reporting success, so a half-delivered frame is never mistaken for a delivered one.

`send()` throws `RuntimeException` on connect failure, handshake error, timeout, or partial write. Catch it and feed the result to the circuit breaker rather than letting it escape into the emitting request.

## Signed webhooks

`WebhookSigner` produces and verifies an HMAC signature over the payload.

```php
use craftpulse\auditkit\siem\WebhookSigner;

$signer = new WebhookSigner();
$signature = $signer->sign($timestamp, $eventId, $body, $secret);
```

| Method | Description |
|---|---|
| `sign(string $timestamp, string $eventId, string $body, string $secret): string` | Returns the signature for a payload, in the form `sha256=<hex>`. |
| `verify(string $signature, string $timestamp, string $eventId, string $body, string $currentSecret, ?string $previousSecret = null): bool` | Checks a signature against the current secret, then against the previous one if given. |

The signed string is `{timestamp}.{eventId}.{body}`. Binding the timestamp and the event id into the signature is what makes a replayed or body-swapped delivery detectable: the same body under a different timestamp does not verify.

Secret rotation is the reason `verify()` takes two secrets. During a rotation window you sign with the new secret and accept either, so receivers that have not yet picked up the new secret keep validating. Drop `$previousSecret` once the window closes; leaving it in place indefinitely means a leaked old secret never actually expires.

Both comparisons are constant-time.

The signer does not name the headers. You choose the header names and pass them to the forwarder, which keeps the signer usable against a receiver whose header convention you do not control.

## HTTP JSON

`HttpJsonForwarder` posts a JSON body to an endpoint and reports the outcome. It covers a signed webhook, Splunk HEC, Datadog, and any generic token-authenticated collector, because the only difference between those is the headers.

```php
use craftpulse\auditkit\siem\HttpJsonForwarder;

$forwarder = new HttpJsonForwarder();

$result = $forwarder->forward('https://siem.example.com/collect', $body, [
    'X-Audit-Timestamp' => $timestamp,
    'X-Audit-Event-Id' => $eventId,
    'X-Audit-Signature' => $signature,
]);

if (!$result->success) {
    // $result->statusCode, $result->errorMessage
}
```

`forward(string $url, string $body, array $headers = []): ForwardResult` never throws. Every failure, including an unreachable host, comes back as a `ForwardResult` with `success` set to `false`.

Three guarantees are enforced in the forwarder rather than left to the caller:

- A URL that does not start with `https://` is refused before any request is made. There is no option to allow plaintext.
- Redirects are never followed, so a 3xx is a failed delivery. An audit forwarder that follows redirects can be walked to an attacker's endpoint.
- The request times out after 10 seconds.

`Content-Type: application/json` is set by the forwarder and your headers are merged underneath it, so passing your own `Content-Type` has no effect.

### `ForwardResult`

| Property | Description |
|---|---|
| `success` | Whether the delivery returned a 2xx status. |
| `statusCode` | The HTTP status, or `null` when the endpoint was never reached. |
| `errorMessage` | A short diagnostic, or `null` on success. |

The result deliberately carries neither the request body nor the signature, so logging a failed result cannot leak either.

## S3

`S3Forwarder` writes one JSON object per event to a bucket, behind an SDK-free seam.

```php
use craftpulse\auditkit\siem\S3Forwarder;

$forwarder = new S3Forwarder($myS3ClientAdapter);
$result = $forwarder->forward('my-audit-bucket', 'audit', $eventId, $body);
```

Objects land at `{keyPrefix}/YYYY/MM/DD/{eventId}.json`, which keeps a bucket browsable by date and makes an object key idempotent for a given event.

| Method | Description |
|---|---|
| `forward(string $bucket, string $keyPrefix, string $eventId, string $body, array $extraArgs = []): ForwardResult` | Writes one object and returns the outcome. |
| `isSdkAvailable(): bool` | Returns whether the AWS SDK is installed, without requiring it. |

`$extraArgs` is passed through to the adapter untouched, which is how Object Lock retention parameters reach the store.

Audit Kit does not require `aws/aws-sdk-php` and ships no concrete client. You implement `S3ClientInterface` in your own plugin:

```php
use craftpulse\auditkit\siem\S3ClientInterface;

class MyS3Client implements S3ClientInterface
{
    public function putObject(string $bucket, string $key, string $body, string $contentType, array $extraArgs = []): void
    {
        // Call the AWS SDK, or anything else that speaks S3
    }
}
```

Constructing `S3Forwarder` with no client is legal and safe: `forward()` returns a failed `ForwardResult` telling you to install the SDK and inject an adapter, rather than fataling. That is what lets a consuming plugin ship S3 support that stays dormant until an operator opts in.

Because the seam is an interface rather than the SDK, the same forwarder works against any S3-compatible store, and you can update your adapter independently of Audit Kit.

## Circuit breaker

`CircuitBreaker` stops a consuming plugin from hammering a collector that is down. It is cache-backed and shared across requests.

```php
use craftpulse\auditkit\siem\CircuitBreaker;

$breaker = new CircuitBreaker(threshold: 5, cooldownSeconds: 300);

if ($breaker->isOpen('my-plugin:splunk')) {
    return;
}

$result = $forwarder->forward($url, $body, $headers);

$result->success
    ? $breaker->recordSuccess('my-plugin:splunk')
    : $breaker->recordFailure('my-plugin:splunk');
```

| Method | Description |
|---|---|
| `isOpen(string $key): bool` | Returns whether the circuit is open and the cooldown has not yet elapsed. |
| `recordFailure(string $key): bool` | Increments the failure counter and returns whether the circuit is now open. |
| `recordSuccess(string $key): void` | Clears the failure counter and closes the circuit. |

The constructor defaults are 5 consecutive failures and a 300 second cooldown. Once the cooldown elapses, `isOpen()` reports `false` again so exactly one probe can go through: a success closes the circuit, a failure re-opens it for another cooldown.

`$key` identifies one destination, not one plugin. Use a distinct key per endpoint (`my-plugin:splunk`, `my-plugin:webhook`) so a dead webhook does not suppress syslog delivery.

Raise the threshold on a collector that is merely slow, and lower it on one where a failed delivery is expensive. Keep the cooldown well above your queue's retry interval, otherwise the breaker never actually pauses anything.
