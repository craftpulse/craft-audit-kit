# Anchoring

A hash chain proves nobody altered it after the fact. It does not prove when it existed, because whoever controls the database controls the chain and could in principle rebuild the whole thing. Anchoring closes that gap: you batch the chain's row hashes into a Merkle root and commit that root to a system you do not control.

Anchoring is optional. A consuming plugin schedules it from its own console command or queue job; the kit ships the primitives and no scheduling.

## Merkle batching

`MerkleTree` folds a batch of row hashes into a single root.

```php
use craftpulse\auditkit\anchoring\MerkleTree;

$tree = new MerkleTree($rowHashes);
$root = $tree->root();
$proof = $tree->proof(0);
```

| Method | Description |
|---|---|
| `root(): string` | Returns the Merkle root as a hex string. |
| `proof(int $index): array` | Returns the inclusion proof for the leaf at that index, as a list of `hash` and `position` pairs. |
| `MerkleTree::rootFromProof(string $leaf, array $proof): string` | Recomputes the root from a single leaf and its proof, without a tree instance. |

The leaves are the hex `rowHash` values in chain order. A parent node is `sha256` over the raw bytes of its two children, an odd node count duplicates the last node, and a single leaf is its own root.

`rootFromProof()` is static so a verifier can prove one row was in an anchored batch while holding only that row and its proof, with no access to the rest of the chain. That is the property worth storing proofs for: it lets you hand an auditor evidence about one event without handing them everything else.

The constructor throws `InvalidArgumentException` on an empty leaf set, and `proof()` throws on an out-of-range index.

## Anchor providers

An anchor provider commits a root to an external system and hands back a receipt.

| Method | Description |
|---|---|
| `anchor(string $merkleRoot): AnchorReceipt` | Commits the hex root to the external system and returns the receipt. |
| `handle(): string` | Returns the provider handle, such as `rfc3161` or `s3-object-lock`. |

### RFC 3161 timestamping

`Rfc3161TsaAnchorProvider` submits the root to a timestamping authority, which returns a signed token proving the root existed before the token was issued.

```php
use craftpulse\auditkit\anchoring\Rfc3161TsaAnchorProvider;

$provider = new Rfc3161TsaAnchorProvider('https://freetsa.org/tsr');
$receipt = $provider->anchor($root);
```

| Method | Description |
|---|---|
| `anchor(string $merkleRoot): AnchorReceipt` | Sends a timestamp request and returns the receipt, with the raw response base64-encoded. |
| `buildRequest(string $merkleRoot): string` | Builds the DER-encoded `TimeStampReq` committing to the root. |
| `readResponseStatus(string $der): int` | Reads the PKIStatus integer out of a `TimeStampResp`. |

A non-2xx HTTP response throws, and so does a PKIStatus other than granted or granted-with-modifications, so a rejected timestamp is never mistaken for an anchor.

The second constructor argument is a transport closure receiving the URL and the DER request and returning a status and response pair. It exists so tests can run the provider without a network, and so you can substitute your own HTTP client. Leave it null to use Guzzle with certificate verification on and a 15 second timeout.

This is the stronger of the two providers for evidentiary purposes, because the proof is cryptographic and verifiable by anyone holding the TSA's certificate. It also means depending on a third party being reachable.

### S3 Object Lock

`S3ObjectLockAnchorProvider` writes the root as an object under COMPLIANCE-mode Object Lock, which no credential can delete or overwrite until the retention date passes.

```php
use craftpulse\auditkit\anchoring\S3ObjectLockAnchorProvider;

$provider = new S3ObjectLockAnchorProvider(
    client: $myS3ClientAdapter,
    bucket: 'my-audit-anchors',
    keyPrefix: 'anchors',
    retentionDays: 2555,
);

$receipt = $provider->anchor($root);
```

Objects land at `{keyPrefix}/YYYY/MM/DD/{merkleRoot}.json`. The retention default is 2555 days, which is seven years.

The client is the same `S3ClientInterface` seam the [SIEM S3 forwarder](siem.md#s3) uses, so the kit never requires the AWS SDK and the same adapter serves both.

COMPLIANCE mode is the point. In governance mode a sufficiently privileged credential can shorten retention, which reduces the anchor to a regular file. Verify your bucket actually has Object Lock enabled in COMPLIANCE mode before treating these anchors as evidence, because the provider cannot tell the difference from the outside.

Choose the TSA when you need a proof a third party can verify independently. Choose Object Lock when you need anchoring that stays inside your own infrastructure, at the cost of the proof resting on your cloud provider's enforcement rather than on cryptography.

Nothing stops you using both. `CertificateGenerator` accepts a list of receipts.

### AnchorReceipt

| Property | Description |
|---|---|
| `provider` | The handle of the provider that produced the receipt. |
| `merkleRoot` | The hex Merkle root that was anchored. |
| `anchoredAt` | The unix timestamp at which the anchor was obtained. |
| `reference` | The external reference, such as an object key or a TSA serial. |
| `rawResponse` | The raw provider response, base64-encoded when binary. |

| Method | Description |
|---|---|
| `toArray(): array` | Returns the receipt as an array, for embedding in a certificate. |

Store `rawResponse`. It is what lets someone verify the anchor against the provider later without trusting your record of it.

### Writing your own provider

Implement `AnchorProviderInterface`. See the implementation of the `craftpulse\auditkit\anchoring\S3ObjectLockAnchorProvider` class, which is the shorter of the two shipped providers.

```php
use craftpulse\auditkit\anchoring\AnchorProviderInterface;
use craftpulse\auditkit\anchoring\AnchorReceipt;

class MyAnchorProvider implements AnchorProviderInterface
{
    // Implement the interface methods
}
```

There is no registration event and no base class, so a provider lives entirely in your own plugin and you can update it independently of Audit Kit.

## Certificates of integrity

`CertificateGenerator` packages a verified chain range, its Merkle root, and its anchor receipts into one signed document.

```php
use craftpulse\auditkit\anchoring\CertificateGenerator;

$generator = new CertificateGenerator();

$certificate = $generator->generate(
    startId: $startId,
    startRowHash: $startRowHash,
    endId: $endId,
    endRowHash: $endRowHash,
    merkleRoot: $root,
    receipts: [$receipt],
    signingKey: $signingKey,
    subject: 'My Site, content audit',
);

$pdf = $generator->toPdf($certificate);
```

| Method | Description |
|---|---|
| `generate(...): array` | Builds the certificate body and returns it with a signature attached. |
| `toPdf(array $certificate): string` | Renders a certificate array as PDF bytes, A4 portrait. |
| `CertificateGenerator::sign(array $body, string $signingKey): string` | Returns the HMAC over the certificate body, excluding any existing signature. |
| `CertificateGenerator::verifySignature(array $certificate, string $signingKey): bool` | Checks a certificate's signature in constant time. |

The body carries a format version, the subject, the generation timestamp, the algorithm, the chain range as four fields, the Merkle root, and every anchor receipt. The signature is an HMAC over the canonicalized body with the signature field removed, so it covers every other field.

Keep the signing key separate from the chain's PII key and separate from `securityKey`. Anyone holding the signing key can mint a certificate that verifies.

The JSON certificate is the artefact that matters; the PDF is a rendering of it for people. Give an auditor both, and give them the JSON export alongside, because the certificate on its own only proves what the root was, not what the rows were.

## The standalone verifier

`bin/verify-audit-chain.php` verifies an export with no Craft, no Composer, and no database access. It is a single procedural PHP file with zero dependencies, which is what makes it something you can hand to an auditor.

```shell
php verify-audit-chain.php export.jsonl [certificate.json]
```

The export is a [JSONL export](export.md#jsonlformatter). The verifier recomputes each line's `sha256(canonical(payload) . previousHash)`, compares it to the stored `rowHash`, and checks that each line's `previousHash` matches the row before it.

Given a certificate as well, it reconstructs the Merkle root from the export's row hashes and compares it to the root in the certificate.

| Exit code | Description |
|---|---|
| `0` | The chain is valid, and the certificate's Merkle root matches if one was given. |
| `1` | A chain break, or a Merkle root that does not match the certificate. |
| `2` | Unreadable input, malformed JSON, or a usage error. |

The first line's stored `previousHash` is trusted as the starting anchor, so the same command verifies both a full chain from genesis and an export of a pruned or bounded range.

Certificate signature checking is off unless you set the `AUDIT_CERT_KEY` environment variable:

```shell
AUDIT_CERT_KEY=your-signing-key php verify-audit-chain.php export.jsonl certificate.json
```

The key is supplied out of band, never bundled with the script, because the script is meant to be published and the key is not.
