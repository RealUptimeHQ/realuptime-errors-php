<?php

declare(strict_types=1);

/**
 * Laravel config for the realuptime Errors SDK. Publish with:
 *
 *     php artisan vendor:publish --tag=realuptime-errors-config
 *
 * or set REALUPTIME_ERRORS_DSN (and friends) in .env and skip publishing
 * entirely — every key below reads from the environment with a safe
 * default.
 */
return [
    /** The per-project ingest key/URL from the realuptime Errors dashboard.
     * Empty disables the SDK entirely (no DSN, no requests, no errors). */
    'dsn' => env('REALUPTIME_ERRORS_DSN', ''),

    /** Your app's release identifier, shown on every issue. */
    'release' => env('REALUPTIME_ERRORS_RELEASE'),

    /** Defaults to Laravel's own APP_ENV when unset. */
    'environment' => env('REALUPTIME_ERRORS_ENVIRONMENT'),

    /** Per-field opt-back-in for fields scrubbed by default, e.g.
     * ['user.email']. Never a global switch. */
    'allow_fields' => array_filter(explode(',', (string) env('REALUPTIME_ERRORS_ALLOW_FIELDS', ''))),

    /** Runtime/platform facts (PHP version, OS, arch) attached to every
     * event. Never hostname or IP. */
    'send_device_info' => (bool) env('REALUPTIME_ERRORS_SEND_DEVICE_INFO', true),

    /** Off by default: see src/Client.php's framesFromException() doc for
     * why capturing local variables is the leakiest thing this SDK can do. */
    'include_local_variables' => (bool) env('REALUPTIME_ERRORS_INCLUDE_LOCAL_VARIABLES', false),
];
