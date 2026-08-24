<?php

declare(strict_types=1);

namespace RealUptime\Errors;

/**
 * Plain-PHP helper: installs a global exception handler that reports
 * uncaught exceptions to the process-wide Client singleton, then chains to
 * whatever handler was previously registered (if any), matching every
 * other language's "capture, then re-raise/re-dispatch" convention in this
 * repo. For frameworks, prefer the framework-specific integration
 * (Laravel: LaravelServiceProvider) — this helper is for scripts, cron
 * jobs, and anything with no framework exception pipeline of its own.
 *
 * Usage:
 *
 *     require 'vendor/autoload.php';
 *     \RealUptime\Errors\Client::init(dsn: 'https://realuptime.io/api/errors/v1/ingest/rue_...');
 *     \RealUptime\Errors\install_exception_handler();
 *
 * Never throws: a failure while capturing or while calling the previous
 * handler is caught so this helper is never the thing that turns a
 * reportable error into an unreportable fatal.
 */

/** @var (callable(\Throwable): void)|null */
$GLOBALS['__realuptime_errors_previous_handler'] = null;
$GLOBALS['__realuptime_errors_handler_installed'] = false;

function install_exception_handler(): void
{
    if ($GLOBALS['__realuptime_errors_handler_installed'] === true) {
        return;
    }

    $previous = set_exception_handler(static function (\Throwable $exception): void {
        try {
            $client = Client::instance();
            if ($client !== null) {
                $client->captureException($exception);
                $client->flush();
            }
        } catch (\Throwable) {
            // never let capture crash the crash handler
        }

        $previous = $GLOBALS['__realuptime_errors_previous_handler'];
        if ($previous !== null) {
            try {
                $previous($exception);
            } catch (\Throwable) {
                // the previous handler misbehaving is not this SDK's problem
            }
        }
    });

    $GLOBALS['__realuptime_errors_previous_handler'] = $previous;
    $GLOBALS['__realuptime_errors_handler_installed'] = true;
}

/** Test/teardown seam: restores whatever handler was in place before
 * install_exception_handler() ran. */
function restore_exception_handler(): void
{
    if ($GLOBALS['__realuptime_errors_handler_installed'] !== true) {
        return;
    }
    restore_exception_handler_php();
    $GLOBALS['__realuptime_errors_previous_handler'] = null;
    $GLOBALS['__realuptime_errors_handler_installed'] = false;
}

/** Thin wrapper so the namespaced restore_exception_handler() above can
 * still reach PHP's own global-namespace function of the same name. */
function restore_exception_handler_php(): void
{
    \restore_exception_handler();
}
