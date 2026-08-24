<?php

declare(strict_types=1);

namespace RealUptime\Errors\Laravel;

use Closure;
use Illuminate\Http\Request;
use RealUptime\Errors\Client;

/**
 * Attaches the current request's method, path, and matched route template
 * to the process-wide Client so a capture during this request (whether
 * from CapturingExceptionHandler or a manual captureException() call)
 * carries request context, the same "route rides, headers/cookies/body
 * never do" contract the JS Express adapter documents in
 * packages/errors-js/adapters/express.md.
 *
 * Registered as a global middleware (see README) so it runs for every
 * request without per-route opt-in; it does no I/O of its own and never
 * throws, so it cannot itself become a source of 500s.
 */
final class RequestContextMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $client = Client::instance();
            if ($client !== null) {
                $route = $request->route();
                $client->setContext('http.method', $request->method());
                $client->setContext('http.path', $request->path());
                if ($route !== null && method_exists($route, 'uri')) {
                    $client->setContext('http.route', '/' . ltrim($route->uri(), '/'));
                }
            }
        } catch (\Throwable) {
            // never let context capture break the request
        }

        return $next($request);
    }

    /** Minimal request array for a manual captureException() call inside a
     * request lifecycle, matching the JS/Python SDKs' `request` shape
     * (method, path, status; headers only when the integrator opts in). */
    public static function requestContext(Request $request, ?int $status = null): array
    {
        $route = $request->route();
        return array_filter([
            'method' => $request->method(),
            'path' => $request->path(),
            'route' => $route !== null && method_exists($route, 'uri') ? '/' . ltrim($route->uri(), '/') : null,
            'status' => $status,
        ], static fn ($v) => $v !== null);
    }
}
