<?php

declare(strict_types=1);

namespace RealUptime\Errors;

/**
 * Scrubs one wire event before it is serialized, ported from
 * packages/errors-js/scrub.ts's `scrubEvent`. Returns a new array; the
 * input is never mutated.
 *
 * v2 fields are touched only when PRESENT: a v1 event goes through here and
 * comes out with exactly the v1 key set it went in with, which is what
 * keeps the vector table's v1 expectations holding byte-for-byte.
 */
final class Event
{
    private function __construct()
    {
    }

    /** @param array<int, string> $allowFields */
    public static function scrub(array $event, array $allowFields = []): array
    {
        $allowed = array_flip(array_map('strtolower', $allowFields));

        $out = $event;
        if (isset($out['message']) && is_string($out['message'])) {
            $out['message'] = Scrub::scrubString($out['message']);
        }
        if (isset($out['request']) && is_array($out['request'])) {
            $out['request'] = self::scrubRequest($out['request'], $allowed);
        }
        if (isset($out['breadcrumbs']) && is_array($out['breadcrumbs'])) {
            $out['breadcrumbs'] = array_map([self::class, 'scrubBreadcrumb'], $out['breadcrumbs']);
        }
        if (isset($out['frames']) && is_array($out['frames'])) {
            $out['frames'] = array_map([self::class, 'scrubFrame'], $out['frames']);
        }
        if (isset($out['user']) && is_array($out['user'])) {
            $out['user'] = self::scrubUser($out['user'], $allowed);
        }
        foreach (['tags', 'context', 'device'] as $key) {
            if (isset($out[$key]) && is_array($out[$key])) {
                $out[$key] = Scrub::scrubStringMap($out[$key]);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $allowed */
    private static function scrubRequest(array $request, array $allowed): array
    {
        $out = $request;
        if (isset($out['path']) && is_string($out['path'])) {
            $out['path'] = Scrub::scrubString($out['path']);
        }
        if (isset($out['route']) && is_string($out['route'])) {
            $out['route'] = Scrub::scrubString($out['route']);
        }
        if (isset($out['headers']) && is_array($out['headers'])) {
            $headers = [];
            foreach ($out['headers'] as $name => $value) {
                $lower = strtolower((string) $name);
                if (in_array($lower, Scrub::REMOVED_HEADERS, true) && !isset($allowed[$lower])) {
                    $headers[$name] = Scrub::SCRUBBED;
                } else {
                    $headers[$name] = is_string($value) ? Scrub::scrubString($value) : $value;
                }
            }
            $out['headers'] = $headers;
        }
        return $out;
    }

    private static function scrubBreadcrumb(mixed $crumb): mixed
    {
        if (!is_array($crumb)) {
            return $crumb;
        }
        $out = $crumb;
        if (isset($out['message']) && is_string($out['message'])) {
            $out['message'] = Scrub::scrubString($out['message']);
        }
        if (isset($out['data']) && is_array($out['data'])) {
            $out['data'] = Scrub::scrubStringMap($out['data']);
        }
        return $out;
    }

    private static function scrubFrame(mixed $frame): mixed
    {
        if (!is_array($frame)) {
            return $frame;
        }
        if (!isset($frame['contextLine']) && !isset($frame['preContext']) && !isset($frame['postContext']) && !isset($frame['vars'])) {
            return $frame;
        }
        $out = $frame;
        if (isset($out['contextLine']) && is_string($out['contextLine'])) {
            $out['contextLine'] = Scrub::scrubString($out['contextLine']);
        }
        foreach (['preContext', 'postContext'] as $key) {
            if (isset($out[$key]) && is_array($out[$key])) {
                $out[$key] = array_map(
                    static fn ($line) => is_string($line) ? Scrub::scrubString($line) : $line,
                    $out[$key],
                );
            }
        }
        if (isset($out['vars']) && is_array($out['vars'])) {
            $out['vars'] = Scrub::scrubStringMap($out['vars']);
        }
        return $out;
    }

    /** @param array<string, mixed> $allowed */
    private static function scrubUser(array $user, array $allowed): array
    {
        $out = $user;
        if (isset($out['id']) && is_string($out['id'])) {
            $out['id'] = Scrub::scrubString($out['id']);
        }
        foreach (Scrub::REMOVED_USER_FIELDS as $field) {
            $key = substr($field, strlen('user.'));
            if (!isset($out[$key]) || !is_string($out[$key])) {
                continue;
            }
            $out[$key] = isset($allowed[strtolower($field)]) ? Scrub::scrubString($out[$key]) : Scrub::SCRUBBED;
        }
        return $out;
    }
}
