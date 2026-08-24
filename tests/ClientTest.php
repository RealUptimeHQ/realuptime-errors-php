<?php

declare(strict_types=1);

namespace RealUptime\Errors\Tests;

use PHPUnit\Framework\TestCase;
use RealUptime\Errors\Client;
use RealUptime\Errors\Transport;

final class ClientTest extends TestCase
{
    /** @return array{0: Transport, 1: \ArrayObject} */
    private function recordingTransport(): array
    {
        $sent = new \ArrayObject();
        $transport = new Transport(
            'https://example.test/ingest',
            httpPost: function (string $url, string $body) use ($sent): array {
                $sent[] = json_decode($body, true);
                return ['status' => 200, 'body' => json_encode(['accepted' => 1, 'droppedOverQuota' => 0, 'overQuota' => false])];
            },
            log: static function (string $message): void {
                // swallow: keep test output clean
            },
        );
        return [$transport, $sent];
    }

    public function testCaptureExceptionScrubsAndSendsAMessageAndFrames(): void
    {
        [$transport, $sent] = $this->recordingTransport();
        $client = new Client(dsn: 'https://example.test/ingest', release: 'v1.0.0', environment: 'test', transport: $transport);

        try {
            throw new \RuntimeException('token sk_live_abcdefgh12345678 rejected');
        } catch (\RuntimeException $e) {
            $client->captureException($e);
        }

        $this->assertCount(1, $sent);
        $batch = $sent[0];
        $this->assertSame('realuptime-errors-php/0.1.0', explode('/', $batch['sdk'])[0] . '/' . explode('/', $batch['sdk'])[1]);
        $event = $batch['events'][0];
        $this->assertSame('token [scrubbed] rejected', $event['message']);
        $this->assertSame('RuntimeException', $event['exceptionType']);
        $this->assertSame('v1.0.0', $event['release']);
        $this->assertSame('test', $event['environment']);
        $this->assertNotNull($event['frames']);
        $this->assertArrayNotHasKey('user', $event);
        $this->assertArrayNotHasKey('tags', $event);
    }

    public function testCaptureMessageWithUserScrubsEmailByDefault(): void
    {
        [$transport, $sent] = $this->recordingTransport();
        $client = new Client(dsn: 'https://example.test/ingest', transport: $transport);

        $client->captureMessage('checkout failed', user: ['id' => 'acct_1', 'email' => 'alice@example.com']);

        $event = $sent[0]['events'][0];
        $this->assertSame('acct_1', $event['user']['id']);
        $this->assertSame('[scrubbed]', $event['user']['email']);
    }

    public function testAllowFieldsOptsUserEmailBackIn(): void
    {
        [$transport, $sent] = $this->recordingTransport();
        $client = new Client(dsn: 'https://example.test/ingest', allowFields: ['user.email'], transport: $transport);

        $client->captureMessage('boom', user: ['email' => 'alice@example.com']);

        $this->assertSame('alice@example.com', $sent[0]['events'][0]['user']['email']);
    }

    public function testStickyTagsAndContextRideEveryCapture(): void
    {
        [$transport, $sent] = $this->recordingTransport();
        $client = new Client(dsn: 'https://example.test/ingest', transport: $transport);

        $client->setTag('tenant', 'acme');
        $client->setContext('cartTotal', '49.99');
        $client->captureMessage('first');
        $client->captureMessage('second', tags: ['plan' => 'growth']);

        $this->assertSame(['tenant' => 'acme'], $sent[0]['events'][0]['tags']);
        $this->assertSame(['tenant' => 'acme', 'plan' => 'growth'], $sent[1]['events'][0]['tags']);
        $this->assertSame(['cartTotal' => '49.99'], $sent[1]['events'][0]['context']);
    }

    public function testBreadcrumbsRideTheNextEventAndReportEvictions(): void
    {
        [$transport, $sent] = $this->recordingTransport();
        $client = new Client(dsn: 'https://example.test/ingest', transport: $transport);

        for ($i = 0; $i < 25; $i++) {
            $client->addBreadcrumb("step {$i}", 'test');
        }
        $client->captureMessage('boom');

        $event = $sent[0]['events'][0];
        $this->assertCount(20, $event['breadcrumbs']);
        $this->assertSame(5, $event['breadcrumbsDropped']);
        $this->assertSame('step 24', $event['breadcrumbs'][19]['message']);
    }

    public function testDeviceInfoDefaultsOnAndCarriesPhpRuntime(): void
    {
        [$transport, $sent] = $this->recordingTransport();
        $client = new Client(dsn: 'https://example.test/ingest', transport: $transport);

        $client->captureMessage('boom');

        $this->assertSame('php', $sent[0]['events'][0]['device']['runtime']);
    }

    public function testCaptureExceptionNeverThrowsEvenWithABrokenTransport(): void
    {
        $transport = new Transport('https://example.test/ingest', httpPost: function (): array {
            throw new \RuntimeException('network down');
        }, log: static function (): void {});
        $client = new Client(dsn: 'https://example.test/ingest', transport: $transport);

        try {
            throw new \LogicException('boom');
        } catch (\LogicException $e) {
            $client->captureException($e);
        }

        $this->addToAssertionCount(1); // reaching here means it never threw
    }

    protected function tearDown(): void
    {
        Client::reset();
    }
}
