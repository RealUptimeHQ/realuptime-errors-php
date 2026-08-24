<?php

declare(strict_types=1);

namespace RealUptime\Errors\Tests;

use PHPUnit\Framework\TestCase;
use RealUptime\Errors\EventBuffer;
use RealUptime\Errors\WireContract;

final class EventBufferTest extends TestCase
{
    public function testPushAndPeekPreserveOrder(): void
    {
        $buffer = new EventBuffer();
        $buffer->push(['message' => 'a']);
        $buffer->push(['message' => 'b']);
        $this->assertSame(2, $buffer->length());
        $this->assertSame([['message' => 'a'], ['message' => 'b']], $buffer->peekBatch(10));
    }

    public function testCommitRemovesOnlyAcknowledgedEvents(): void
    {
        $buffer = new EventBuffer();
        $buffer->push(['message' => 'a']);
        $buffer->push(['message' => 'b']);
        $buffer->commit(1);
        $this->assertSame(1, $buffer->length());
        $this->assertSame([['message' => 'b']], $buffer->peekBatch(10));
    }

    public function testOverflowDropsOldestAndCountsIt(): void
    {
        $buffer = new EventBuffer();
        for ($i = 0; $i < WireContract::BUFFER_MAX + 5; $i++) {
            $buffer->push(['message' => (string) $i]);
        }
        $this->assertSame(WireContract::BUFFER_MAX, $buffer->length());
        $this->assertSame(5, $buffer->takeDropped());
        // taking resets the counter
        $this->assertSame(0, $buffer->takeDropped());
    }

    public function testRestoreDroppedGivesBackAFailedReport(): void
    {
        $buffer = new EventBuffer();
        $buffer->push(['message' => 'a']);
        for ($i = 0; $i < WireContract::BUFFER_MAX; $i++) {
            $buffer->push(['message' => 'x']);
        }
        $dropped = $buffer->takeDropped();
        $this->assertGreaterThan(0, $dropped);
        $buffer->restoreDropped($dropped);
        $this->assertSame($dropped, $buffer->takeDropped());
    }
}
