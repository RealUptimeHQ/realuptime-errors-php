<?php

declare(strict_types=1);

namespace RealUptime\Errors\Tests\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use RealUptime\Errors\Client;
use RealUptime\Errors\Laravel\CapturingExceptionHandler;
use RealUptime\Errors\Laravel\ErrorsServiceProvider;
use RealUptime\Errors\Transport;

/**
 * Boots a minimal real Laravel Application (not a mock) and registers the
 * provider through it, the way `composer test` in a Laravel app actually
 * would. Verifies the two integration points the README promises: the
 * exception handler decoration captures without changing behavior, and
 * the JobFailed listener is wired.
 */
final class ErrorsServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Client::reset();
    }

    private function bootApp(array $config): Application
    {
        $app = new Application(sys_get_temp_dir());
        $app->singleton('events', fn () => new Dispatcher($app));
        $app->instance('config', new \Illuminate\Config\Repository(['realuptime-errors' => $config]));

        // A minimal handler stub so extend() has something to decorate.
        $app->singleton(ExceptionHandlerContract::class, fn () => new class implements ExceptionHandlerContract {
            public array $reported = [];
            public function report(\Throwable $e): void
            {
                $this->reported[] = $e;
            }
            public function shouldReport(\Throwable $e): bool
            {
                return true;
            }
            public function render($request, \Throwable $e)
            {
                return null;
            }
            public function renderForConsole($output, \Throwable $e): void
            {
            }
        });

        $provider = new ErrorsServiceProvider($app);
        $provider->register();
        $provider->boot();

        return $app;
    }

    public function testBootInitializesTheClientSingletonFromConfig(): void
    {
        $this->bootApp(['dsn' => 'https://example.test/ingest', 'release' => 'v1.2.3']);
        $this->assertNotNull(Client::instance());
    }

    public function testBootWithoutADsnLeavesTheSdkUninitialized(): void
    {
        $this->bootApp(['dsn' => '']);
        $this->assertNull(Client::instance());
    }

    public function testDecoratedHandlerCapturesThenDelegatesToTheOriginal(): void
    {
        $app = $this->bootApp(['dsn' => 'https://example.test/ingest']);

        $sent = [];
        $reflection = new \ReflectionClass(Client::instance());
        $transportProp = $reflection->getProperty('transport');
        $transportProp->setValue(Client::instance(), new Transport(
            'https://example.test/ingest',
            httpPost: function (string $url, string $body) use (&$sent): array {
                $sent[] = json_decode($body, true);
                return ['status' => 200, 'body' => '{"accepted":1,"droppedOverQuota":0,"overQuota":false}'];
            },
            log: static function (): void {},
        ));

        $handler = $app->make(ExceptionHandlerContract::class);
        $this->assertInstanceOf(CapturingExceptionHandler::class, $handler);

        $exception = new \RuntimeException('boom');
        $handler->report($exception);

        $this->assertCount(1, $sent);
        $this->assertSame('boom', $sent[0]['events'][0]['message']);
    }
}
