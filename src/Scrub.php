<?php

declare(strict_types=1);

namespace RealUptime\Errors;

/**
 * PII scrub-by-default, client-side (docs/errors-plan.md, "PII
 * scrub-by-default, specified"). Ported byte-for-byte from
 * packages/errors-js/scrub.ts and packages/errors-py/realuptime_errors.py;
 * the shared vectors in scrub-vectors.json (vendored into this package,
 * tests/ScrubVectorsParityTest.php pins it byte-equal to the JS source of
 * truth) pin all three SDKs and the server's second net to identical
 * behavior. Runs BEFORE serialization so a sensitive value never transits
 * the wire.
 *
 * The five pattern rules, in the order they run over every scrubbed
 * string: JWT three-dot shape, prefixed secret keys, Luhn-valid card-shaped
 * digit runs, long hex runs (32+), long base64-ish runs (40+, no slash).
 *
 * Field removal (whole-value replacement) is a SEPARATE, explicit step
 * applied by Event::scrub(): removed headers (authorization,
 * proxy-authorization, cookie, set-cookie) and v2 identity fields
 * (user.email, user.username), both allow-listable by exact name. Keys are
 * never scrubbed, only values; see the module doc in the JS source for the
 * full rationale.
 */
final class Scrub
{
    public const SCRUBBED = '[scrubbed]';

    /** Headers whose value is removed whole unless allow-listed. */
    public const REMOVED_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie'];

    /** Identity fields removed whole unless allow-listed by these exact
     * dotted names. `user.id` is deliberately absent. */
    public const REMOVED_USER_FIELDS = ['user.email', 'user.username'];

    private const JWT_RE = '/\beyJ[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}\b/';
    private const PREFIXED_KEY_RE = '/\b(?:sk|pk|rk|ghp|gho|ghs|xox[a-z]|rua|rue|ru_live)_[A-Za-z0-9_-]{8,}/';
    private const CARD_RE = '/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/';
    private const HEX_RE = '/\b[0-9a-fA-F]{32,}\b/';
    private const BASE64_RE = '/(?<![A-Za-z0-9+_=-])[A-Za-z0-9+_-]{40,}={0,2}/';

    private function __construct()
    {
    }

    private static function luhnValid(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = ord($digits[$i]) - 48;
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = !$double;
        }
        return $sum % 10 === 0;
    }

    /** Applies the five pattern rules to one string. Pure; exported so the
     * vector tests can drive it directly. */
    public static function scrubString(string $value): string
    {
        $out = (string) preg_replace(self::JWT_RE, self::SCRUBBED, $value);
        $out = (string) preg_replace(self::PREFIXED_KEY_RE, self::SCRUBBED, $out);
        $out = (string) preg_replace_callback(self::CARD_RE, static function (array $m): string {
            $run = $m[0];
            $digits = str_replace([' ', '-'], '', $run);
            $len = strlen($digits);
            if ($len >= 13 && $len <= 19 && self::luhnValid($digits)) {
                return self::SCRUBBED;
            }
            return $run;
        }, $out);
        $out = (string) preg_replace(self::HEX_RE, self::SCRUBBED, $out);
        $out = (string) preg_replace(self::BASE64_RE, self::SCRUBBED, $out);
        return $out;
    }

    /** A flat string map (tags, custom context, device, frame locals):
     * values pass the pattern rules, keys are left alone. */
    public static function scrubStringMap(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            $out[$key] = is_string($value) ? self::scrubString($value) : $value;
        }
        return $out;
    }
}
