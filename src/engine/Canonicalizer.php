<?php
/**
 * Audit Kit plugin for Craft CMS 5.x
 *
 * Foundational, tamper-evident audit primitives for Craft.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\auditkit\engine;

use craft\helpers\Json;

/**
 * Canonicalizer is the byte-stable JSON encoder that drives every audit hash
 * chain in the CraftPulse estate. It is lifted byte-for-byte from Password
 * Policy's `AuditLogService::canonicalize()` / `_sortRecursive()` — the two
 * implementations MUST produce identical bytes for identical input, because live
 * Password Policy 5.1.x installs carry production chains that were written with
 * PP's own copy of this code. Any drift silently invalidates every chain hash in
 * the wild.
 *
 * Encoding rules (the frozen contract, pinned by the ported canonicalisation
 * tests + the golden-vector test):
 *
 * - Keys sorted alphabetically (`SORT_STRING`) recursively at every depth.
 * - JSON flags `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
 * - `null` preserved (not stripped).
 * - Booleans encoded as `true`/`false` (not coerced to `1`/`0`).
 * - Integers bare (not quoted).
 * - No whitespace anywhere.
 * - Numerically-indexed (list) arrays keep their order — only associative
 *   arrays sort their string keys. The `array_is_list()` guard is load-bearing:
 *   without it `ksort(SORT_STRING)` reorders lists of ≥ 10 entries
 *   (`'10' < '2'`).
 *
 * The verifier feeds stored payloads through this same method to recompute
 * hashes. Sharing the writer's code path is precisely what guarantees
 * bit-identical output.
 *
 * @author CraftPulse
 * @since 1.0.0
 */
final class Canonicalizer
{
    // Const Properties
    // =========================================================================

    /**
     * Canonical-payload `dateCreated` format. UTC, ISO 8601 with the literal
     * `Z` suffix. Sub-second precision is dropped (MySQL DATETIME resolution).
     * A single source of truth — the writer, the verifier, and any external
     * verifier produce bit-identical output only when this matches exactly.
     *
     * @var string
     * @since 1.0.0
     */
    public const CANONICAL_DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * Stable sentinel for the genesis row's `previousHash` — sixty-four zero
     * hex chars, the same width as a SHA-256 digest so a chain-walk treats it
     * as a hash without a null-handling branch.
     *
     * @var string
     * @since 1.0.0
     */
    public const GENESIS_PREVIOUS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    // Static Methods
    // =========================================================================

    /**
     * Returns the canonical-JSON encoding of an audit payload — the byte-stable
     * input to the chain SHA-256.
     *
     * @param array<mixed, mixed> $payload
     * @return string
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function canonicalize(array $payload): string
    {
        $sorted = self::sortRecursive($payload);

        return Json::encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Recursively sorts an array's string keys for canonicalisation. List
     * arrays keep their integer order — the `array_is_list()` guard prevents
     * `ksort(SORT_STRING)` from reordering list values (`'10' < '2'`).
     *
     * @param array<mixed, mixed> $value
     * @return array<mixed, mixed>
     *
     * @author CraftPulse
     * @since 1.0.0
     */
    public static function sortRecursive(array $value): array
    {
        foreach ($value as $key => $inner) {
            if (is_array($inner)) {
                $value[$key] = self::sortRecursive($inner);
            }
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
