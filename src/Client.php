<?php

declare(strict_types=1);

namespace RealUptime\Errors;

/**
 * realuptime-errors: minimal PHP error tracking SDK (docs/errors-plan.md,
 * REA-255). One wire contract with the JS and Python SDKs
 * (packages/errors-js/types.ts); the shared scrub vectors
 * (scrub-vectors.json, vendored here byte-for-byte) pin all three SDKs and
 * the server's second net to identical scrubbing.
 *
 * Contract with the host app, non-negotiable: THIS SDK NEVER THROWS out of
 * a public method. A broken SDK logs once and goes quiet; an error tracker
 * that crashes the app it watches is worse than none.
 *
 * Usage:
 *
 *     $errors = new \RealUptime\Errors\Client(
 *         dsn: 'https://realuptime.io/api/errors/v1/ingest/rue_...',
 *         release: 'v2.4.1',
 *         environment: 'production',
 *     );
 *     $errors->captureException($exception);
 *
 * Or the process-wide singleton used by the plain-PHP helper
 * (src/functions.php) and the Laravel service provider:
 *
 *     \RealUptime\Errors\Client::init(dsn: '...');
 *     \RealUptime\Errors\Client::instance()->captureException($exception);
 */
final class Client
{
    private static ?self $instance = null;

    private string $dsn;
    private ?string $release;
    private ?string $environment;
    /** @var array<int, string> */
    private array $allowFields;
    private Transport $transport;
    /** @var array<string, string>|null */
    private ?array $device;
    private bool $includeLocalVariables;

    /** @var array<int, array<string, mixed>> */
    private array $breadcrumbs = [];
    private int $breadcrumbsEvicted = 0;

    /** @var array<string, string>|null */
    private ?array $user = null;
    /** @var array<string, string> */
    private array $tags = [];
    /** @var array<string, string> */
    private array $context = [];

    /** @param array<int, string> $allowFields */
    public function __construct(
        string $dsn,
        ?string $release = null,
        ?string $environment = null,
        array $allowFields = [],
        bool $sendDeviceInfo = true,
        bool $includeLocalVariables = false,
        ?Transport $transport = null,
    ) {
        $this->dsn = $dsn;
        $this->release = $release;
        $this->environment = $environment;
        $this->allowFields = $allowFields;
        $this->transport = $transport ?? new Transport($dsn);
        $this->device = $sendDeviceInfo ? self::detectDevice() : null;
        $this->includeLocalVariables = $includeLocalVariables;
    }

    /**
     * Initializes the process-wide singleton. Safe to call twice (last call
     * wins). A missing DSN logs once and stays inert. Never throws.
     *
     * @param array<int, string> $allowFields
     */
    public static function init(
        string $dsn,
        ?string $release = null,
        ?string $environment = null,
        array $allowFields = [],
        bool $sendDeviceInfo = true,
        bool $includeLocalVariables = false,
    ): void {
        try {
            if ($dsn === '') {
                fwrite(STDERR, "[realuptime-errors] init called without a dsn; error reporting is disabled.\n");
                return;
            }
            self::$instance = new self($dsn, $release, $environment, $allowFields, $sendDeviceInfo, $includeLocalVariables);
        } catch (\Throwable $failure) {
            fwrite(STDERR, '[realuptime-errors] init failed: ' . $failure->getMessage() . "\n");
        }
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    /** Test/teardown seam: drops the singleton. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private static function detectDevice(): ?array
    {
        try {
            return [
                'runtime' => 'php',
                'runtimeVersion' => PHP_VERSION,
                'platform' => strtolower(PHP_OS_FAMILY),
                'arch' => php_uname('m') ?: null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Records one breadcrumb onto the bounded trail. The trail rides the
     * NEXT captured event, newest last, at most MAX_BREADCRUMBS_PER_EVENT;
     * older entries are evicted and the eviction count rides the event as
     * breadcrumbsDropped, so a truncated trail is never presented as the
     * whole story. Never throws.
     *
     * @param array<string, string>|null $data
     */
    public function addBreadcrumb(string $message, ?string $category = null, ?array $data = null): void
    {
        try {
            $crumbData = null;
            if ($data !== null) {
                $crumbData = [];
                $i = 0;
                foreach ($data as $name => $value) {
                    if ($i++ >= WireContract::MAX_BREADCRUMB_DATA_ENTRIES) {
                        break;
                    }
                    $crumbData[self::clip((string) $name, WireContract::MAX_STRING_LENGTH)] = self::clip((string) $value, WireContract::MAX_STRING_LENGTH);
                }
            }
            $crumb = [
                'timestamp' => gmdate('Y-m-d\TH:i:s') . '.000Z',
                'category' => $category !== null ? self::clip($category, 100) : null,
                'message' => self::clip($message, WireContract::MAX_STRING_LENGTH),
                'data' => $crumbData,
            ];
            $this->breadcrumbs[] = $crumb;
            if (count($this->breadcrumbs) > WireContract::MAX_BREADCRUMBS_PER_EVENT) {
                array_shift($this->breadcrumbs);
                $this->breadcrumbsEvicted++;
            }
        } catch (\Throwable) {
            // never throw
        }
    }

    /**
     * v2 (REA-182): sets the sticky identity applied to every subsequent
     * event. null clears it (sign-out). Only id/email/username are
     * carried. email and username are "[scrubbed]" before serialization
     * unless the matching allowFields entry is set. Never throws.
     *
     * @param array<string, string>|null $user
     */
    public function setUser(?array $user): void
    {
        try {
            if ($user === null) {
                $this->user = null;
                return;
            }
            $next = [];
            foreach (['id', 'email', 'username'] as $key) {
                if (isset($user[$key]) && is_string($user[$key])) {
                    $next[$key] = self::clip($user[$key], WireContract::MAX_STRING_LENGTH);
                }
            }
            $this->user = $next !== [] ? $next : null;
        } catch (\Throwable) {
            // never throw
        }
    }

    /** v2: sets one sticky tag; a null value removes it. Tags past
     * MAX_TAGS_PER_EVENT are ignored rather than evicting an existing one.
     * Never throws. */
    public function setTag(string $key, ?string $value): void
    {
        try {
            if ($key === '') {
                return;
            }
            $name = self::clip($key, WireContract::MAX_CONTEXT_KEY_LENGTH);
            if ($value === null) {
                unset($this->tags[$name]);
                return;
            }
            if (!isset($this->tags[$name]) && count($this->tags) >= WireContract::MAX_TAGS_PER_EVENT) {
                return;
            }
            $this->tags[$name] = self::clip($value, WireContract::MAX_STRING_LENGTH);
        } catch (\Throwable) {
            // never throw
        }
    }

    /** @param array<string, string> $tags */
    public function setTags(array $tags): void
    {
        foreach ($tags as $name => $value) {
            $this->setTag((string) $name, $value);
        }
    }

    /** v2: sets one sticky custom-context entry, or merges a whole map when
     * $key is an array. null value removes the named entry; setContext(null)
     * clears everything. Never throws.
     *
     * @param string|array<string, string>|null $key
     */
    public function setContext(string|array|null $key, ?string $value = null): void
    {
        try {
            if ($key === null) {
                $this->context = [];
                return;
            }
            if (is_array($key)) {
                foreach ($key as $name => $entry) {
                    $this->setContext((string) $name, $entry);
                }
                return;
            }
            if ($key === '') {
                return;
            }
            $name = self::clip($key, WireContract::MAX_CONTEXT_KEY_LENGTH);
            if ($value === null) {
                unset($this->context[$name]);
                return;
            }
            if (!isset($this->context[$name]) && count($this->context) >= WireContract::MAX_CONTEXT_ENTRIES) {
                return;
            }
            $this->context[$name] = self::clip($value, WireContract::MAX_STRING_LENGTH);
        } catch (\Throwable) {
            // never throw
        }
    }

    private static function clip(string $value, int $limit): string
    {
        return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
    }

    /** @param array<string, string> $value */
    private static function boundedMap(array $value, int $limit): array
    {
        $out = [];
        foreach ($value as $name => $entry) {
            if (count($out) >= $limit) {
                break;
            }
            if ($name === '' || !is_string($entry)) {
                continue;
            }
            $out[self::clip((string) $name, WireContract::MAX_CONTEXT_KEY_LENGTH)] = self::clip($entry, WireContract::MAX_STRING_LENGTH);
        }
        return $out;
    }

    /**
     * Walks a Throwable's trace into wire frames. Innermost first, matching
     * the JS/Python SDKs' stack order. PHP's getTrace() already puts the
     * call site of the exception (not the constructor) first, which is the
     * innermost frame we want.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function framesFromException(\Throwable $exception): ?array
    {
        $frames = [];

        // The throw site itself: getFile()/getLine() point at where `throw`
        // happened, which PHP's trace array does not otherwise include.
        $frames[] = [
            'file' => self::clip($exception->getFile(), WireContract::MAX_STRING_LENGTH),
            'function' => null,
            'line' => $exception->getLine(),
            'inApp' => self::isInApp($exception->getFile()),
        ];

        foreach ($exception->getTrace() as $entry) {
            if (count($frames) >= WireContract::MAX_FRAMES_PER_EVENT) {
                break;
            }
            $file = $entry['file'] ?? '';
            $function = $entry['function'] ?? null;
            if (isset($entry['class'])) {
                $function = $entry['class'] . ($entry['type'] ?? '::') . $function;
            }
            $frame = [
                'file' => self::clip((string) $file, WireContract::MAX_STRING_LENGTH),
                'function' => $function !== null ? self::clip((string) $function, WireContract::MAX_STRING_LENGTH) : null,
                'line' => $entry['line'] ?? null,
                'inApp' => self::isInApp((string) $file),
            ];
            if ($this->includeLocalVariables && isset($entry['args']) && is_array($entry['args'])) {
                $vars = [];
                foreach ($entry['args'] as $i => $arg) {
                    if (count($vars) >= WireContract::MAX_LOCAL_VARS_PER_FRAME) {
                        break;
                    }
                    $text = self::stringifyArg($arg);
                    $vars['arg' . $i] = self::clip($text, WireContract::MAX_LOCAL_VAR_LENGTH);
                }
                if ($vars !== []) {
                    $frame['vars'] = $vars;
                }
            }
            $frames[] = $frame;
        }

        return $frames === [] ? null : $frames;
    }

    private static function stringifyArg(mixed $arg): string
    {
        try {
            if (is_scalar($arg) || $arg === null) {
                return var_export($arg, true);
            }
            if (is_object($arg)) {
                return get_class($arg);
            }
            if (is_array($arg)) {
                return 'array(' . count($arg) . ')';
            }
            return '<unrepresentable>';
        } catch (\Throwable) {
            return '<unrepresentable>';
        }
    }

    private static function isInApp(string $file): bool
    {
        return $file !== '' && !str_contains($file, '/vendor/');
    }

    /**
     * @param array<string, mixed>|null $request
     * @param array<int, string>|null $fingerprint
     * @param array<string, string>|null $user
     * @param array<string, string>|null $tags
     * @param array<string, string>|null $context
     */
    private function buildEvent(
        string $message,
        ?string $exceptionType,
        ?array $frames,
        ?array $request = null,
        ?array $fingerprint = null,
        ?string $release = null,
        ?string $environment = null,
        ?array $user = null,
        ?array $tags = null,
        ?array $context = null,
    ): array {
        $breadcrumbs = $this->breadcrumbs !== [] ? array_values($this->breadcrumbs) : null;

        $event = [
            'occurredAt' => gmdate('Y-m-d\TH:i:s') . '.000Z',
            'message' => self::clip($message, WireContract::MAX_MESSAGE_LENGTH),
            'exceptionType' => $exceptionType !== null ? self::clip($exceptionType, WireContract::MAX_STRING_LENGTH) : null,
            'release' => $release ?? $this->release,
            'environment' => $environment ?? $this->environment,
            'frames' => $frames,
            'request' => $request,
            'fingerprint' => $fingerprint,
            'breadcrumbs' => $breadcrumbs,
            'breadcrumbsDropped' => $this->breadcrumbsEvicted,
        ];

        // v2 fields are emitted only when NON-EMPTY, so an integration that
        // never touches the v2 API keeps producing byte-identical v1
        // payloads.
        $mergedUser = $this->user;
        if ($user !== null) {
            $mergedUser = array_merge($mergedUser ?? [], array_intersect_key($user, array_flip(['id', 'email', 'username'])));
        }
        if ($mergedUser !== null && $mergedUser !== []) {
            $event['user'] = $mergedUser;
        }

        $mergedTags = array_merge($this->tags, self::boundedMap($tags ?? [], WireContract::MAX_TAGS_PER_EVENT));
        if ($mergedTags !== []) {
            $event['tags'] = $mergedTags;
        }

        $mergedContext = array_merge($this->context, self::boundedMap($context ?? [], WireContract::MAX_CONTEXT_ENTRIES));
        if ($mergedContext !== []) {
            $event['context'] = $mergedContext;
        }

        if ($this->device !== null) {
            $event['device'] = $this->device;
        }

        return $event;
    }

    /**
     * Reports an exception. Never throws.
     *
     * v2: $user / $tags / $context are per-capture overrides merged OVER
     * the sticky scope set by setUser / setTag / setContext.
     *
     * @param array<string, mixed>|null $request
     * @param array<int, string>|null $fingerprint
     * @param array<string, string>|null $user
     * @param array<string, string>|null $tags
     * @param array<string, string>|null $context
     */
    public function captureException(
        \Throwable $exception,
        ?array $request = null,
        ?array $fingerprint = null,
        ?string $release = null,
        ?string $environment = null,
        ?array $user = null,
        ?array $tags = null,
        ?array $context = null,
    ): void {
        try {
            $event = $this->buildEvent(
                $exception->getMessage() !== '' ? $exception->getMessage() : get_class($exception),
                get_class($exception),
                $this->framesFromException($exception),
                request: $request,
                fingerprint: $fingerprint,
                release: $release,
                environment: $environment,
                user: $user,
                tags: $tags,
                context: $context,
            );
            $this->transport->enqueue(Event::scrub($event, $this->allowFields));
        } catch (\Throwable $failure) {
            try {
                fwrite(STDERR, '[realuptime-errors] captureException failed: ' . $failure->getMessage() . "\n");
            } catch (\Throwable) {
                // never throw
            }
        }
    }

    /**
     * Reports a plain message. Never throws.
     *
     * @param array<string, mixed>|null $request
     * @param array<int, string>|null $fingerprint
     * @param array<string, string>|null $user
     * @param array<string, string>|null $tags
     * @param array<string, string>|null $context
     */
    public function captureMessage(
        string $message,
        ?array $request = null,
        ?array $fingerprint = null,
        ?string $release = null,
        ?string $environment = null,
        ?array $user = null,
        ?array $tags = null,
        ?array $context = null,
    ): void {
        try {
            $event = $this->buildEvent(
                $message,
                null,
                null,
                request: $request,
                fingerprint: $fingerprint,
                release: $release,
                environment: $environment,
                user: $user,
                tags: $tags,
                context: $context,
            );
            $this->transport->enqueue(Event::scrub($event, $this->allowFields));
        } catch (\Throwable) {
            // never throw
        }
    }

    /** Delivers anything buffered. Never throws. */
    public function flush(): void
    {
        try {
            $this->transport->flush();
        } catch (\Throwable) {
            // never throw
        }
    }
}
