<?php

declare(strict_types=1);

namespace RealUptime\Errors\Tests;

use PHPUnit\Framework\TestCase;
use RealUptime\Errors\Client;
use RealUptime\Errors\Event;
use RealUptime\Errors\WireContract;

/**
 * Pins the wire contract, the apps/agent/wire-contract.test.ts convention
 * ported to PHP: every limit mirrored from packages/errors-js/types.ts,
 * and the batch/event field-name shape a v1 payload must keep.
 */
final class WireContractTest extends TestCase
{
    public function testLimitsMatchTheJsSdk(): void
    {
        $this->assertSame(50, WireContract::MAX_EVENTS_PER_BATCH);
        $this->assertSame(4000, WireContract::MAX_MESSAGE_LENGTH);
        $this->assertSame(50, WireContract::MAX_FRAMES_PER_EVENT);
        $this->assertSame(512, WireContract::MAX_STRING_LENGTH);
        $this->assertSame(20, WireContract::MAX_BREADCRUMBS_PER_EVENT);
        $this->assertSame(10, WireContract::MAX_BREADCRUMB_DATA_ENTRIES);
        $this->assertSame(200, WireContract::BUFFER_MAX);
    }

    public function testV2CapsMatchTheJsSdk(): void
    {
        $this->assertSame(20, WireContract::MAX_TAGS_PER_EVENT);
        $this->assertSame(20, WireContract::MAX_CONTEXT_ENTRIES);
        $this->assertSame(64, WireContract::MAX_CONTEXT_KEY_LENGTH);
        $this->assertSame(20, WireContract::MAX_LOCAL_VARS_PER_FRAME);
        $this->assertSame(256, WireContract::MAX_LOCAL_VAR_LENGTH);
        $this->assertSame(4096, WireContract::MAX_CONTEXT_BYTES);
        $this->assertSame(200, WireContract::HARD_MAX_MAP_ENTRIES);
    }

    public function testV1EventKeySetIsExactlyTen(): void
    {
        $event = [
            'occurredAt' => '2026-08-21T00:00:00.000Z',
            'message' => 'm',
            'exceptionType' => null,
            'release' => null,
            'environment' => null,
            'frames' => null,
            'request' => null,
            'fingerprint' => null,
            'breadcrumbs' => null,
            'breadcrumbsDropped' => 0,
        ];
        $this->assertCount(10, $event);
        // scrub() must not add any v2 key to a v1-shaped event.
        $this->assertSame(array_keys($event), array_keys(Event::scrub($event)));
    }

    protected function tearDown(): void
    {
        Client::reset();
    }
}
