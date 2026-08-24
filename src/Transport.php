<?php

declare(strict_types=1);

namespace RealUptime\Errors;

/**
 * Delivery: batched, buffered, backed off, and incapable of crashing the
 * host app. Ported from packages/errors-js/client.ts; same disciplines:
 *
 *   - Failures never throw out of the SDK. Everything is caught; the first
 *     failure of each kind logs ONCE and further ones are silent.
 *   - Transient failures (network, 5xx, unparseable body) back off
 *     exponentially and the events stay buffered.
 *   - `over_quota` responses stop delivery until the reset instant the
 *     server names.
 *   - `key_revoked` (403) disables the SDK for the process lifetime after
 *     one log line naming the fix.
 *   - `rate_limited` (429) is transient: back off and keep the events.
 *
 * PHP's request lifecycle is short-lived and synchronous (no persistent
 * background loop like Node's event loop or a Python thread), so delivery
 * happens inline on enqueue() / flush(), using ext-curl with a short
 * timeout. A Laravel queue job hook (Laravel\QueueFailedJobHook) exists so
 * a slow ingest call never blocks a web request; see the README.
 */
final class Transport
{
    public const SEND_TIMEOUT_S = 10;
    public const BACKOFF_START_S = 5;
    public const BACKOFF_MAX_S = 300;

    private EventBuffer $buffer;
    private string $endpoint;
    /** @var callable(string $method, string $url, string $body): array{status:int, body:string} */
    private $httpPost;
    /** @var callable(): float */
    private $now;
    /** @var callable(string $message): void */
    private $log;

    private float $backoffS = 0.0;
    private float $nextAttemptAt = 0.0;
    private float $pausedUntil = 0.0;
    private bool $disabled = false;
    /** @var array<string, true> */
    private array $loggedKinds = [];

    public function __construct(
        string $endpoint,
        ?callable $httpPost = null,
        ?callable $now = null,
        ?callable $log = null,
    ) {
        $this->buffer = new EventBuffer();
        $this->endpoint = $endpoint;
        $this->httpPost = $httpPost ?? [$this, 'curlPost'];
        $this->now = $now ?? static fn (): float => microtime(true);
        $this->log = $log ?? static function (string $message): void {
            fwrite(STDERR, $message . "\n");
        };
    }

    /** @return array{status:int, body:string} */
    private function curlPost(string $url, string $body): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => ''];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['content-type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::SEND_TIMEOUT_S,
            CURLOPT_CONNECTTIMEOUT => self::SEND_TIMEOUT_S,
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0 || $response === false) {
            throw new \RuntimeException('curl error ' . $errno);
        }
        return ['status' => $status, 'body' => (string) $response];
    }

    private function logOnce(string $kind, string $message): void
    {
        if (isset($this->loggedKinds[$kind])) {
            return;
        }
        $this->loggedKinds[$kind] = true;
        try {
            ($this->log)("[realuptime-errors] {$message}");
        } catch (\Throwable) {
            // never let logging crash the host app
        }
    }

    /** @param array<string, mixed> $event */
    public function enqueue(array $event): void
    {
        if ($this->disabled) {
            return;
        }
        $this->buffer->push($event);
        $this->flush();
    }

    /** Sends what is due. Never throws. */
    public function flush(): void
    {
        try {
            $this->deliver();
        } catch (\Throwable $failure) {
            $this->logOnce('internal', 'delivery failed internally: ' . $failure->getMessage());
        }
    }

    private function deliver(): void
    {
        while ($this->buffer->length() > 0) {
            $now = ($this->now)();
            if ($this->disabled || $now < $this->nextAttemptAt || $now < $this->pausedUntil) {
                return;
            }

            $events = $this->buffer->peekBatch(WireContract::MAX_EVENTS_PER_BATCH);
            $droppedClient = $this->buffer->takeDropped();
            $batch = [
                'sdk' => WireContract::SDK_NAME . '/' . WireContract::SDK_VERSION,
                'droppedClient' => $droppedClient,
                'events' => $events,
            ];

            try {
                $result = ($this->httpPost)($this->endpoint, (string) json_encode($batch));
            } catch (\Throwable) {
                $this->buffer->restoreDropped($droppedClient);
                $this->backOff('network', 'cannot reach the ingest endpoint; buffering and retrying');
                return;
            }

            $status = $result['status'];
            $body = [];
            if ($result['body'] !== '') {
                $decoded = json_decode($result['body'], true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }

            if ($status >= 200 && $status < 300) {
                $this->buffer->commit(count($events));
                $this->backoffS = 0.0;
                $this->nextAttemptAt = 0.0;
                if (($body['overQuota'] ?? false) === true) {
                    $resetsAt = $body['quota']['resetsAt'] ?? null;
                    $this->pauseUntil(is_string($resetsAt) ? $resetsAt : null);
                    $this->logOnce(
                        'over-quota',
                        'monthly event quota reached; pausing until the window resets. '
                        . 'Dropped events are counted and shown on your realuptime Errors dashboard.',
                    );
                    return;
                }
                continue;
            }

            $reason = $body['reason'] ?? null;

            if ($status === 403 && $reason === 'key_revoked') {
                $this->disabled = true;
                $this->logOnce(
                    'revoked',
                    "this project's ingest key was revoked; error reporting is disabled for this process. "
                    . 'Rotate the key in realuptime Errors settings and redeploy with the new DSN.',
                );
                return;
            }

            if ($status === 429 && $reason === 'over_quota') {
                $this->buffer->commit(count($events));
                $resetsAt = $body['resetsAt'] ?? null;
                $this->pauseUntil(is_string($resetsAt) ? $resetsAt : null);
                $this->logOnce(
                    'over-quota',
                    'monthly event quota reached; pausing until the window resets. '
                    . 'Dropped events are counted and shown on your realuptime Errors dashboard.',
                );
                return;
            }

            if ($status === 400) {
                $this->buffer->commit(count($events));
                $this->buffer->restoreDropped($droppedClient);
                $error = $body['error'] ?? 'no detail';
                $this->logOnce(
                    'malformed',
                    "the server refused a batch as malformed: {$error}. This is an SDK bug worth reporting.",
                );
                continue;
            }

            $this->buffer->restoreDropped($droppedClient);
            $this->backOff('transient', "ingest endpoint answered {$status}; buffering and retrying");
            return;
        }
    }

    private function pauseUntil(?string $resetsAt): void
    {
        $parsed = null;
        if ($resetsAt !== null) {
            $ts = strtotime($resetsAt);
            $parsed = $ts !== false ? (float) $ts : null;
        }
        $this->pausedUntil = $parsed ?? (($this->now)() + 3600.0);
    }

    private function backOff(string $kind, string $message): void
    {
        $this->backoffS = $this->backoffS <= 0 ? self::BACKOFF_START_S : min($this->backoffS * 2, self::BACKOFF_MAX_S);
        $this->nextAttemptAt = ($this->now)() + $this->backoffS;
        $this->logOnce($kind, "{$message} (backing off).");
    }
}
