<?php

declare(strict_types=1);

namespace RealUptime\Errors;

/**
 * The Errors wire contract (docs/errors-plan.md, "The SDKs: two languages,
 * one contract"), ported from packages/errors-js/types.ts. DUPLICATED, not
 * imported, same convention as the JS and Python SDKs: this package ships
 * into customer processes and its dependency graph must stay auditable at
 * a glance. tests/WireContractTest.php pins every constant against the
 * JS source of truth.
 */
final class WireContract
{
    public const SDK_NAME = 'realuptime-errors-php';
    public const SDK_VERSION = '0.1.0';

    public const MAX_EVENTS_PER_BATCH = 50;
    public const MAX_MESSAGE_LENGTH = 4000;
    public const MAX_FRAMES_PER_EVENT = 50;
    public const MAX_STRING_LENGTH = 512;
    public const MAX_BREADCRUMBS_PER_EVENT = 20;
    public const MAX_BREADCRUMB_DATA_ENTRIES = 10;
    public const BUFFER_MAX = 200;

    // v2 (REA-182) caps, mirrored from packages/errors-js/types.ts.
    public const MAX_TAGS_PER_EVENT = 20;
    public const MAX_CONTEXT_ENTRIES = 20;
    public const MAX_CONTEXT_KEY_LENGTH = 64;
    public const MAX_LOCAL_VARS_PER_FRAME = 20;
    public const MAX_LOCAL_VAR_LENGTH = 256;
    public const MAX_CONTEXT_BYTES = 4096;
    public const HARD_MAX_MAP_ENTRIES = 200;

    private function __construct()
    {
    }
}
