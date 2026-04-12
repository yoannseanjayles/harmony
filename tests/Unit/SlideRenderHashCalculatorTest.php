<?php

namespace App\Tests\Unit;

use App\Slide\SlideRenderHashCalculator;
use PHPUnit\Framework\TestCase;

/**
 * T167 — Unit tests for SlideRenderHashCalculator and cache invalidation logic.
 */
final class SlideRenderHashCalculatorTest extends TestCase
{
    private SlideRenderHashCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new SlideRenderHashCalculator('1', '');
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function testCompute_IsDeterministic(): void
    {
        $hash1 = $this->calculator->compute('{"title":"Harmony"}', '{}');
        $hash2 = $this->calculator->compute('{"title":"Harmony"}', '{}');

        self::assertSame($hash1, $hash2);
    }

    public function testCompute_ReturnsSha256Length(): void
    {
        $hash = $this->calculator->compute('{"title":"Test"}', '{}');

        // SHA-256 hex digest is always 64 characters
        self::assertSame(64, strlen($hash));
    }

    // ── content change invalidates hash ──────────────────────────────────────

    public function testCompute_ChangesWhenContentJsonChanges(): void
    {
        $hash1 = $this->calculator->compute('{"title":"Before"}', '{}');
        $hash2 = $this->calculator->compute('{"title":"After"}', '{}');

        self::assertNotSame($hash1, $hash2);
    }

    public function testCompute_ChangesWhenThemeJsonChanges(): void
    {
        $hash1 = $this->calculator->compute('{"title":"Same"}', '{"accent":"violet"}');
        $hash2 = $this->calculator->compute('{"title":"Same"}', '{"accent":"green"}');

        self::assertNotSame($hash1, $hash2);
    }

    public function testCompute_ChangesWhenTemplateVersionChanges(): void
    {
        $calcV1 = new SlideRenderHashCalculator('1', '');
        $calcV2 = new SlideRenderHashCalculator('2', '');

        $hash1 = $calcV1->compute('{"title":"Same"}', '{}');
        $hash2 = $calcV2->compute('{"title":"Same"}', '{}');

        self::assertNotSame($hash1, $hash2);
    }

    public function testCompute_ChangesWhenAssetsVersionChanges(): void
    {
        $calcNoAssets = new SlideRenderHashCalculator('1', '');
        $calcWithAssets = new SlideRenderHashCalculator('1', 'v2');

        $hash1 = $calcNoAssets->compute('{"title":"Same"}', '{}');
        $hash2 = $calcWithAssets->compute('{"title":"Same"}', '{}');

        self::assertNotSame($hash1, $hash2);
    }

    // ── JSON key ordering normalisation ──────────────────────────────────────

    public function testCompute_IsStableAcrossKeyOrdering(): void
    {
        // Same logical content, different key ordering
        $hash1 = $this->calculator->compute('{"title":"T","label":"L"}', '{}');
        $hash2 = $this->calculator->compute('{"label":"L","title":"T"}', '{}');

        self::assertSame($hash1, $hash2, 'Hash must be stable regardless of JSON key order.');
    }

    public function testCompute_NormalisesNestedKeyOrdering(): void
    {
        $hash1 = $this->calculator->compute('{"outer":{"b":2,"a":1}}', '{}');
        $hash2 = $this->calculator->compute('{"outer":{"a":1,"b":2}}', '{}');

        self::assertSame($hash1, $hash2);
    }

    public function testCompute_IsStableForThemeKeyOrdering(): void
    {
        $hash1 = $this->calculator->compute('{}', '{"bg":"dark","accent":"violet"}');
        $hash2 = $this->calculator->compute('{}', '{"accent":"violet","bg":"dark"}');

        self::assertSame($hash1, $hash2);
    }

    // ── malformed JSON ───────────────────────────────────────────────────────

    public function testCompute_HandlesMalformedContentJson(): void
    {
        $hash = $this->calculator->compute('not valid json', '{}');

        self::assertSame(64, strlen($hash));
    }

    public function testCompute_HandlesMalformedThemeJson(): void
    {
        $hash = $this->calculator->compute('{"title":"T"}', 'not valid json');

        self::assertSame(64, strlen($hash));
    }

    public function testCompute_MalformedContentProducesSameHashAsEmpty(): void
    {
        // Both should normalise to '{}' → same hash for given theme + versions
        $hash1 = $this->calculator->compute('not json', '{}');
        $hash2 = $this->calculator->compute('{}', '{}');

        self::assertSame($hash1, $hash2);
    }

    // ── normalizeJson ────────────────────────────────────────────────────────

    public function testNormalizeJson_SortsTopLevelKeys(): void
    {
        $normalized = $this->calculator->normalizeJson('{"z":1,"a":2}');

        self::assertSame('{"a":2,"z":1}', $normalized);
    }

    public function testNormalizeJson_SortsNestedKeys(): void
    {
        $normalized = $this->calculator->normalizeJson('{"outer":{"z":1,"a":2}}');

        self::assertSame('{"outer":{"a":2,"z":1}}', $normalized);
    }

    public function testNormalizeJson_ReturnsFallbackForInvalidJson(): void
    {
        self::assertSame('{}', $this->calculator->normalizeJson('not json'));
    }

    public function testNormalizeJson_ReturnsFallbackForScalarInput(): void
    {
        self::assertSame('{}', $this->calculator->normalizeJson('"just a string"'));
        self::assertSame('{}', $this->calculator->normalizeJson('42'));
    }

    public function testNormalizeJson_PreservesArrays(): void
    {
        $normalized = $this->calculator->normalizeJson('{"items":["b","a"]}');

        // Arrays should NOT be sorted — only object keys are sorted
        self::assertSame('{"items":["b","a"]}', $normalized);
    }

    public function testNormalizeJson_HandlesEmptyObject(): void
    {
        self::assertSame('{}', $this->calculator->normalizeJson('{}'));
    }
}
