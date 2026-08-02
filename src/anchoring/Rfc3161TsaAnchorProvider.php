<?php
/**
 * Audit Kit module for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\anchoring;

use Carbon\Carbon;
use Craft;
use RuntimeException;

/**
 * Rfc3161TsaAnchorProvider anchors a Merkle root to an RFC 3161 Time-Stamp
 * Authority (a free FreeTSA-style endpoint works out of the box). It builds a
 * minimal TimeStampReq committing to the root's SHA-256, POSTs it as
 * `application/timestamp-query`, and reads the response status — the returned
 * TimeStampToken is a third-party cryptographic proof that the root existed at
 * the stamped time.
 *
 * The ASN.1 surface is intentionally minimal ({@see Asn1}): a TimeStampReq is a
 * short, fixed shape, and keeping the encoder small keeps it auditable. The HTTP
 * transport is injectable so the request encoding and response parsing are
 * tested without a network round-trip; the default transport uses Craft's Guzzle
 * client.
 *
 * TimeStampReq ::= SEQUENCE {
 *   version         INTEGER (v1),
 *   messageImprint  SEQUENCE { AlgorithmIdentifier(sha256), OCTET STRING(hash) },
 *   certReq         BOOLEAN
 * }
 *
 * @author CraftPulse
 * @since 1.0.0
 */
class Rfc3161TsaAnchorProvider implements AnchorProviderInterface
{
    // Const Properties
    // =========================================================================

    /**
     * @var string DER OID for the SHA-256 hash algorithm.
     *
     * @since 1.0.0
     */
    public const OID_SHA256 = '2.16.840.1.101.3.4.2.1';

    // Private Properties
    // =========================================================================

    /**
     * @var callable|null The injected HTTP transport, or null to use Guzzle.
     *
     * @since 1.0.0
     */
    private $_transport;

    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param string $url the TSA endpoint URL
     * @param callable(string, string): array{0: int, 1: string}|null $transport
     * an optional transport `fn(url, requestDer): [httpStatus, responseDer]`;
     * defaults to a Guzzle POST
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function __construct(
        private readonly string $url,
        ?callable $transport = null,
    ) {
        $this->_transport = $transport;
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function anchor(string $merkleRoot): AnchorReceipt
    {
        $request = $this->buildRequest($merkleRoot);
        [$status, $responseDer] = $this->_send($request);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("TSA returned HTTP {$status}.");
        }

        $pkiStatus = self::readResponseStatus($responseDer);

        // 0 = granted, 1 = grantedWithMods; anything else is a rejection.
        if ($pkiStatus !== 0 && $pkiStatus !== 1) {
            throw new RuntimeException("TSA rejected the timestamp request (PKIStatus {$pkiStatus}).");
        }

        return new AnchorReceipt(
            provider: $this->handle(),
            merkleRoot: $merkleRoot,
            anchoredAt: Carbon::now('UTC')->getTimestamp(),
            reference: substr(hash('sha256', $responseDer), 0, 32),
            rawResponse: base64_encode($responseDer),
        );
    }

    /**
     * Builds the DER-encoded TimeStampReq committing to the given hex root.
     *
     * @param string $merkleRoot the hex Merkle root
     * @return string the DER request bytes
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function buildRequest(string $merkleRoot): string
    {
        $binaryRoot = hex2bin($merkleRoot);

        if ($binaryRoot === false) {
            throw new RuntimeException('The Merkle root is not valid hex.');
        }

        $digest = hash('sha256', $binaryRoot, true);

        $algorithmIdentifier = Asn1::sequence(
            Asn1::oid(self::OID_SHA256) . Asn1::null(),
        );

        $messageImprint = Asn1::sequence(
            $algorithmIdentifier . Asn1::octetString($digest),
        );

        return Asn1::sequence(
            Asn1::integer(1) . $messageImprint . Asn1::boolean(true),
        );
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public function handle(): string
    {
        return 'rfc3161';
    }

    // Static Methods
    // =========================================================================

    /**
     * Reads the PKIStatus integer from a TimeStampResp. The response is a
     * SEQUENCE whose first element is a PKIStatusInfo SEQUENCE whose first
     * element is the status INTEGER.
     *
     * @param string $der the response DER
     * @return int the PKIStatus value
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function readResponseStatus(string $der): int
    {
        // Outer SEQUENCE.
        [, $respContent] = Asn1::readLength($der, 1);
        // PKIStatusInfo SEQUENCE begins at respContent (tag 0x30).
        [, $statusInfoContent] = Asn1::readLength($der, $respContent + 1);
        // First element is the status INTEGER.
        [$status] = Asn1::readInteger($der, $statusInfoContent);

        return $status;
    }

    // Private Methods
    // =========================================================================

    /**
     * Sends the request through the injected transport or Guzzle, returning
     * `[httpStatus, responseBytes]`.
     *
     * @param string $request the DER request
     * @return array{0: int, 1: string}
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private function _send(string $request): array
    {
        if ($this->_transport !== null) {
            return ($this->_transport)($this->url, $request);
        }

        $client = Craft::createGuzzleClient([
            'verify' => true,
            'http_errors' => false,
            'timeout' => 15,
        ]);

        $response = $client->request('POST', $this->url, [
            'headers' => [
                'Content-Type' => 'application/timestamp-query',
                'Accept' => 'application/timestamp-reply',
            ],
            'body' => $request,
        ]);

        return [$response->getStatusCode(), (string)$response->getBody()->getContents()];
    }
}
