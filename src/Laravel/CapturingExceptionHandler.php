<?php

declare(strict_types=1);

namespace RealUptime\Errors\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use RealUptime\Errors\Client;

/**
 * Decorates Laravel's bound ExceptionHandlerContract: `report()` captures
 * to realuptime, then delegates to the original handler so every other
 * behavior (logging, Flare, Sentry, whatever else is bound) is completely
 * unaffected. `render()` and `renderForConsole()` pass straight through
 * unchanged; this class never decides what a response looks like, only
 * observes.
 */
final class CapturingExceptionHandler implements ExceptionHandlerContract
{
    public function __construct(private readonly ExceptionHandlerContract $inner)
    {
    }

    public function report(\Throwable $e): void
    {
        try {
            $client = Client::instance();
            if ($client !== null && $this->shouldReport($e)) {
                $client->captureException($e);
            }
        } catch (\Throwable) {
            // never let capture prevent the real handler from reporting
        }

        $this->inner->report($e);
    }

    public function shouldReport(\Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function render($request, \Throwable $e)
    {
        return $this->inner->render($request, $e);
    }

    public function renderForConsole($output, \Throwable $e): void
    {
        $this->inner->renderForConsole($output, $e);
    }
}
