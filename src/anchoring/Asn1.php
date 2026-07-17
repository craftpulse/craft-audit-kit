<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\anchoring;

/**
 * Asn1 is a deliberately minimal DER encoder/decoder — just enough surface to
 * build an RFC 3161 TimeStampReq and read the status of a TimeStampResp, no
 * general-purpose ASN.1 library. Keeping the surface tiny keeps it auditable and
 * testable: every method here is exercised by the anchoring tests against known
 * byte vectors.
 *
 * All encoders return raw DER bytes; the length encoder handles both the short
 * form (< 128) and the long form.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
final class Asn1
{
    // Const Properties
    // =========================================================================

    /**
     * @var int DER tag for BOOLEAN.
     *
     * @since 1.0.0
     */
    public const TAG_BOOLEAN = 0x01;

    /**
     * @var int DER tag for INTEGER.
     *
     * @since 1.0.0
     */
    public const TAG_INTEGER = 0x02;

    /**
     * @var int DER tag for NULL.
     *
     * @since 1.0.0
     */
    public const TAG_NULL = 0x05;

    /**
     * @var int DER tag for OBJECT IDENTIFIER.
     *
     * @since 1.0.0
     */
    public const TAG_OID = 0x06;

    /**
     * @var int DER tag for OCTET STRING.
     *
     * @since 1.0.0
     */
    public const TAG_OCTET_STRING = 0x04;

    /**
     * @var int DER tag for a constructed SEQUENCE.
     *
     * @since 1.0.0
     */
    public const TAG_SEQUENCE = 0x30;

    // Static Methods
    // =========================================================================

    /**
     * Encodes a BOOLEAN.
     *
     * @param bool $value
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function boolean(bool $value): string
    {
        return self::_tlv(self::TAG_BOOLEAN, $value ? "\xff" : "\x00");
    }

    /**
     * Encodes a non-negative INTEGER from its raw big-endian bytes, inserting a
     * leading zero when the high bit is set (DER integers are signed).
     *
     * @param int $value a small non-negative integer
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function integer(int $value): string
    {
        $bytes = ltrim(pack('N', $value), "\x00");

        if ($bytes === '') {
            $bytes = "\x00";
        }

        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return self::_tlv(self::TAG_INTEGER, $bytes);
    }

    /**
     * Encodes a NULL.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function null(): string
    {
        return self::_tlv(self::TAG_NULL, '');
    }

    /**
     * Encodes an OBJECT IDENTIFIER from its dotted string form.
     *
     * @param string $oid the dotted OID (e.g. `2.16.840.1.101.3.4.2.1`)
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function oid(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        // The first two arcs collapse into one byte: 40 * arc1 + arc2.
        $body = chr(40 * $parts[0] + $parts[1]);

        foreach (array_slice($parts, 2) as $arc) {
            $body .= self::_base128($arc);
        }

        return self::_tlv(self::TAG_OID, $body);
    }

    /**
     * Encodes an OCTET STRING around raw bytes.
     *
     * @param string $bytes
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function octetString(string $bytes): string
    {
        return self::_tlv(self::TAG_OCTET_STRING, $bytes);
    }

    /**
     * Wraps concatenated DER elements in a SEQUENCE.
     *
     * @param string $contents the concatenated child DER
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function sequence(string $contents): string
    {
        return self::_tlv(self::TAG_SEQUENCE, $contents);
    }

    /**
     * Reads the INTEGER value at the given offset in a DER buffer, returning
     * `[value, nextOffset]`. Minimal — used to read the small PKIStatus integer
     * from a TimeStampResp.
     *
     * @param string $der the DER buffer
     * @param int $offset the offset of the INTEGER tag
     * @return array{0: int, 1: int}
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function readInteger(string $der, int $offset): array
    {
        // Tag byte, then length, then big-endian content.
        [$length, $contentOffset] = self::readLength($der, $offset + 1);
        $value = 0;

        for ($i = 0; $i < $length; $i++) {
            $value = ($value << 8) | ord($der[$contentOffset + $i]);
        }

        return [$value, $contentOffset + $length];
    }

    /**
     * Reads a DER length field starting at the given offset, returning
     * `[length, contentOffset]`.
     *
     * @param string $der the DER buffer
     * @param int $offset the offset of the length byte
     * @return array{0: int, 1: int}
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function readLength(string $der, int $offset): array
    {
        $first = ord($der[$offset]);

        if (($first & 0x80) === 0) {
            return [$first, $offset + 1];
        }

        $numBytes = $first & 0x7f;
        $length = 0;

        for ($i = 1; $i <= $numBytes; $i++) {
            $length = ($length << 8) | ord($der[$offset + $i]);
        }

        return [$length, $offset + 1 + $numBytes];
    }

    // Private Methods
    // =========================================================================

    /**
     * Encodes an OID arc in base-128 with continuation bits.
     *
     * @param int $arc
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private static function _base128(int $arc): string
    {
        if ($arc === 0) {
            return "\x00";
        }

        $stack = [];
        while ($arc > 0) {
            array_unshift($stack, $arc & 0x7f);
            $arc >>= 7;
        }

        // Set the continuation bit on every 7-bit group except the last.
        $out = '';
        $len = count($stack);
        foreach ($stack as $i => $group) {
            $out .= chr($i < $len - 1 ? ($group | 0x80) : $group);
        }

        return $out;
    }

    /**
     * Encodes a tag-length-value with a DER length field.
     *
     * @param int $tag
     * @param string $contents
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private static function _tlv(int $tag, string $contents): string
    {
        return chr($tag) . self::_encodeLength(strlen($contents)) . $contents;
    }

    /**
     * Encodes a DER length field (short form under 128, else long form).
     *
     * @param int $length
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    private static function _encodeLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
