<?php

declare(strict_types=1);

namespace RealUptime\Errors;

/**
 * Bounded in-memory event buffer, ported from packages/errors-js/buffer.ts:
 * oldest dropped when full, every drop counted, and the count is reported
 * on the NEXT successful batch (`droppedClient` on the wire) so even
 * client-side drops reach the product's visible drop counters. In-memory
 * only; an error SDK that writes to the customer's filesystem is over the
 * line.
 */
final class EventBuffer
{
    /** @var array<int, array<string, mixed>> */
    private array $events = [];
    private int $droppedSinceReport = 0;

    /** @param array<string, mixed> $event */
    public function push(array $event): void
    {
        if (count($this->events) >= WireContract::BUFFER_MAX) {
            array_shift($this->events);
            $this->droppedSinceReport++;
        }
        $this->events[] = $event;
    }

    public function length(): int
    {
        return count($this->events);
    }

    /** Reads up to $n events without removing them; commit($n) removes
     * them only after the server acknowledged the batch, so a failed
     * delivery retries the same events instead of losing them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function peekBatch(int $n): array
    {
        return array_slice($this->events, 0, $n);
    }

    public function commit(int $n): void
    {
        array_splice($this->events, 0, $n);
    }

    /** The drop count to carry on the NEXT batch. Reset to zero only when
     * the caller is about to put it on the wire; if delivery fails the
     * caller must give it back via restoreDropped(). */
    public function takeDropped(): int
    {
        $n = $this->droppedSinceReport;
        $this->droppedSinceReport = 0;
        return $n;
    }

    public function restoreDropped(int $n): void
    {
        $this->droppedSinceReport += $n;
    }
}
