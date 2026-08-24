<?php

declare(strict_types=1);

namespace RealUptime\Errors\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pins the "no runtime deps beyond ext-json/ext-curl" promise as a test
 * rather than trusting it, the apps/agent/wire-contract.test.ts convention
 * (dependency hygiene) ported to this package. The Laravel integration
 * (src/Laravel/*) is allowed to reference illuminate/* symbols because
 * `laravel/framework` is a dev-only require used purely for autoloading
 * those interfaces during static analysis and tests; a real Laravel host
 * app provides them at runtime, so this package itself declares no
 * `illuminate/*` require.
 */
final class DependencyHygieneTest extends TestCase
{
    public function testComposerJsonDeclaresOnlyPhpAndExtensions(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['php', 'ext-json', 'ext-curl'], array_keys($composer['require']));
    }

    public function testCoreSourceFilesNeverReferenceIlluminate(): void
    {
        foreach (['Client.php', 'Event.php', 'EventBuffer.php', 'Scrub.php', 'Transport.php', 'WireContract.php', 'functions.php'] as $file) {
            $source = file_get_contents(__DIR__ . "/../src/{$file}");
            $this->assertStringNotContainsString('Illuminate', $source, "{$file} must stay framework-agnostic; Laravel wiring lives only under src/Laravel/");
        }
    }
}
