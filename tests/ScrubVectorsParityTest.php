<?php

declare(strict_types=1);

namespace RealUptime\Errors\Tests;

use PHPUnit\Framework\TestCase;
use RealUptime\Errors\Event;
use RealUptime\Errors\Scrub;

/**
 * Drives the SHARED scrub vectors (the internal-docs repo's errors-plan.md, "The SDKs: two
 * languages, one contract") against this SDK's scrub implementation, the
 * same convention as errors-js/scrub.test.ts and
 * errors-py/tests/test_scrub.py.
 *
 * Two separate guarantees:
 *
 *   1. `test_vendored_copy_matches_js_source_of_truth`: this package's
 *      vendored scrub-vectors.json is BYTE-IDENTICAL to
 *      errors-js/scrub-vectors.json. A drift here means someone
 *      updated one copy and not the other.
 *   2. `test_every_vector_scrubs_identically`: this SDK's own scrub output
 *      matches every vector's `expected` client-side result.
 */
final class ScrubVectorsParityTest extends TestCase
{
    private static function vendoredPath(): string
    {
        return __DIR__ . '/../scrub-vectors.json';
    }

    private static function jsSourcePath(): string
    {
        return __DIR__ . '/../../errors-js/scrub-vectors.json';
    }

    public function testVendoredCopyMatchesJsSourceOfTruth(): void
    {
        $jsPath = self::jsSourcePath();
        if (!is_file($jsPath)) {
            $this->markTestSkipped('errors-js/scrub-vectors.json not present in this checkout');
        }
        $vendored = file_get_contents(self::vendoredPath());
        $source = file_get_contents($jsPath);
        $this->assertSame($source, $vendored, 'errors-php/scrub-vectors.json has drifted from errors-js/scrub-vectors.json; re-copy it verbatim.');
    }

    public function testEveryVectorScrubsIdentically(): void
    {
        $vectors = json_decode((string) file_get_contents(self::vendoredPath()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($vectors['vectors']);
        $this->assertGreaterThan(20, count($vectors['vectors']));

        foreach ($vectors['vectors'] as $vector) {
            $allowFields = $vector['allowFields'] ?? [];
            $scrubbed = Event::scrub($vector['event'], $allowFields);
            $this->assertSame(
                $vector['expected'],
                $scrubbed,
                'vector failed: ' . $vector['name'],
            );
        }
    }

    public function testScrubStringDirectlyOnAFewVectors(): void
    {
        $this->assertSame('Payment failed for [scrubbed]', Scrub::scrubString('Payment failed for 4242424242424242'));
        $this->assertSame('order 4242424242424241 not found', Scrub::scrubString('order 4242424242424241 not found'));
        $this->assertSame('HTTP 502 after 12345 ms', Scrub::scrubString('HTTP 502 after 12345 ms'));
    }

    /** Error messages often carry user input, and this runs in the
     * customer's own process: a pattern that backtracks on it stalls their
     * app, and PCRE's backtrack limit turns it into a silent non-scrub. */
    public function testHostileSchemeAndColonRunIsFast(): void
    {
        $hostile = str_repeat('a.', 300) . '://' . str_repeat(':', 2000);
        $started = microtime(true);
        Scrub::scrubString($hostile);
        $this->assertLessThan(0.25, microtime(true) - $started);
        $this->assertSame(PREG_NO_ERROR, preg_last_error());
    }
}
