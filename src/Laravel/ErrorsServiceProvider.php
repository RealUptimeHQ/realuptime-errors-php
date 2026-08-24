<?php

declare(strict_types=1);

namespace RealUptime\Errors\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use RealUptime\Errors\Client;

/**
 * Laravel integration (config/realuptime-errors.php + this provider).
 * Wires into Laravel's own exception pipeline rather than adding a second
 * one: it decorates the framework's bound ExceptionHandlerContract so
 * every exception Laravel itself reports (`report()`) is also captured
 * here, then delegates back to the original handler unchanged — the same
 * "observe, never replace" shape the JS/Python adapters use (see
 * packages/errors-py/adapters/README-flask.md's design notes).
 *
 * Also listens for `Illuminate\Queue\Events\JobFailed` (the queue job
 * hook this SDK ships): a job that exhausts its retries and fails is an
 * error the customer's queue worker process saw, and worker processes are
 * exactly the code paths a request/response exception handler never runs
 * for.
 *
 * `RequestContextMiddleware` (registered separately, see the README) adds
 * the current request's method/path/route to whatever this provider
 * captures, the same way the JS Express adapter's route metadata rides
 * with a capture without this SDK ever reading cookies or bodies.
 */
final class ErrorsServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/realuptime-errors.php', 'realuptime-errors');
    }

    public function boot(): void
    {
        $config = $this->app->make('config')->get('realuptime-errors', []);

        if (empty($config['dsn'])) {
            return;
        }

        Client::init(
            dsn: (string) $config['dsn'],
            release: $config['release'] ?? null,
            environment: $config['environment'] ?? $this->currentEnvironment(),
            allowFields: $config['allow_fields'] ?? [],
            sendDeviceInfo: $config['send_device_info'] ?? true,
            includeLocalVariables: $config['include_local_variables'] ?? false,
        );

        $this->decorateExceptionHandler($this->app);
        $this->listenForFailedJobs();

        $this->publishes([
            __DIR__ . '/../../config/realuptime-errors.php' => $this->app->configPath('realuptime-errors.php'),
        ], 'realuptime-errors-config');
    }

    /** Falls back to null (rather than throwing) in a minimal/test
     * container where the 'env' binding was never registered. A real
     * Laravel app always has it. */
    private function currentEnvironment(): ?string
    {
        try {
            return $this->app->environment();
        } catch (\Throwable) {
            return null;
        }
    }

    private function decorateExceptionHandler(Application $app): void
    {
        $app->extend(ExceptionHandlerContract::class, static function (ExceptionHandlerContract $handler): ExceptionHandlerContract {
            return new CapturingExceptionHandler($handler);
        });
    }

    private function listenForFailedJobs(): void
    {
        $this->app->make('events')->listen(JobFailed::class, static function (JobFailed $event): void {
            $client = Client::instance();
            if ($client === null) {
                return;
            }
            $client->captureException(
                $event->exception,
                tags: ['queue.connection' => $event->connectionName, 'queue.job' => $event->job->resolveName()],
            );
            $client->flush();
        });
    }
}
