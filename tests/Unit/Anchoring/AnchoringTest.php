<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Tests for the anchoring module: Merkle root + inclusion-proof round-trip, the
 * RFC 3161 TimeStampReq DER encoding and response-status parse (via a fake
 * transport), the S3 Object Lock provider's COMPLIANCE-mode write, and the
 * Certificate of Integrity's signature round-trip + tamper detection.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\auditkit\anchoring\AnchorReceipt;
use craftpulse\auditkit\anchoring\CertificateGenerator;
use craftpulse\auditkit\anchoring\MerkleTree;
use craftpulse\auditkit\anchoring\Rfc3161TsaAnchorProvider;
use craftpulse\auditkit\anchoring\S3ObjectLockAnchorProvider;
use craftpulse\auditkit\siem\S3ClientInterface;

it('builds a Merkle root and a proof that recomputes it', function() {
    $leaves = array_map(fn(int $i): string => hash('sha256', "leaf-{$i}"), range(0, 4));
    $tree = new MerkleTree($leaves);
    $root = $tree->root();

    expect($root)->toHaveLength(64);

    // Every leaf's inclusion proof recomputes the same root.
    foreach ($leaves as $index => $leaf) {
        expect(MerkleTree::rootFromProof($leaf, $tree->proof($index)))->toBe($root);
    }
});

it('returns a single leaf as its own root', function() {
    $leaf = hash('sha256', 'only');

    expect((new MerkleTree([$leaf]))->root())->toBe($leaf);
});

it('encodes a TimeStampReq committing to the root SHA-256', function() {
    $provider = new Rfc3161TsaAnchorProvider('https://freetsa.org/tsr');
    $root = hash('sha256', 'batch');
    $der = $provider->buildRequest($root);

    // Outer SEQUENCE tag.
    expect(bin2hex($der[0]))->toBe('30');
    // Contains the DER sha256 OID (06 09 60 86 48 01 65 03 04 02 01).
    expect(str_contains($der, hex2bin('0609608648016503040201')))->toBeTrue();
    // Contains the 32-byte digest of the (binary) root.
    expect(str_contains($der, hash('sha256', hex2bin($root), true)))->toBeTrue();
});

it('reads the PKIStatus from a TimeStampResp and anchors on granted', function() {
    // Minimal TimeStampResp: SEQUENCE { PKIStatusInfo SEQUENCE { INTEGER 0 } }.
    $granted = hex2bin('30053003020100');

    expect(Rfc3161TsaAnchorProvider::readResponseStatus($granted))->toBe(0);

    $provider = new Rfc3161TsaAnchorProvider(
        'https://tsa.test/tsr',
        fn(string $url, string $req): array => [200, $granted],
    );

    $receipt = $provider->anchor(hash('sha256', 'batch'));

    expect($receipt)->toBeInstanceOf(AnchorReceipt::class)
        ->and($receipt->provider)->toBe('rfc3161')
        ->and($receipt->rawResponse)->toBe(base64_encode($granted));
});

it('rejects a non-granted TSA status', function() {
    // status 2 = rejection.
    $rejected = hex2bin('30053003020102');
    $provider = new Rfc3161TsaAnchorProvider(
        'https://tsa.test/tsr',
        fn(string $url, string $req): array => [200, $rejected],
    );

    expect(fn() => $provider->anchor(hash('sha256', 'batch')))
        ->toThrow(RuntimeException::class);
});

it('writes an Object Lock anchor in COMPLIANCE mode', function() {
    $captured = [];
    $client = new class($captured) implements S3ClientInterface {
        public function __construct(private array &$captured)
        {
        }

        public function putObject(string $bucket, string $key, string $body, string $contentType, array $extraArgs = []): void
        {
            $this->captured[] = compact('bucket', 'key', 'extraArgs');
        }
    };

    $root = hash('sha256', 'batch');
    $receipt = (new S3ObjectLockAnchorProvider($client, 'lock-bucket', 'anchors', 2555))->anchor($root);

    expect($receipt->provider)->toBe('s3-object-lock')
        ->and($captured[0]['extraArgs']['ObjectLockMode'])->toBe('COMPLIANCE')
        ->and($captured[0]['extraArgs'])->toHaveKey('ObjectLockRetainUntilDate')
        ->and($captured[0]['key'])->toContain($root);
});

it('signs a certificate and detects tampering', function() {
    $gen = new CertificateGenerator();
    $receipt = new AnchorReceipt('rfc3161', hash('sha256', 'r'), time(), 'ref-1', 'raw');

    $cert = $gen->generate(
        startId: 1,
        startRowHash: hash('sha256', 'a'),
        endId: 10,
        endRowHash: hash('sha256', 'b'),
        merkleRoot: hash('sha256', 'root'),
        receipts: [$receipt],
        signingKey: 'secret-key',
        subject: 'Playground',
    );

    expect($cert['signature'])->toHaveLength(64)
        ->and(CertificateGenerator::verifySignature($cert, 'secret-key'))->toBeTrue()
        ->and(CertificateGenerator::verifySignature($cert, 'wrong-key'))->toBeFalse();

    // Tamper the chain range: the signature no longer verifies.
    $cert['chain']['endId'] = 999;
    expect(CertificateGenerator::verifySignature($cert, 'secret-key'))->toBeFalse();
});

it('renders a certificate to PDF', function() {
    $gen = new CertificateGenerator();
    $cert = $gen->generate(1, hash('sha256', 'a'), 2, hash('sha256', 'b'), hash('sha256', 'root'), [], 'k');

    expect(substr($gen->toPdf($cert), 0, 5))->toBe('%PDF-');
});
