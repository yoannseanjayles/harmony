<?php

namespace App\Tests\Unit;

use App\Theme\ThemeTokenValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ThemeTokenValidator covering every token category and edge cases.
 */
final class ThemeTokenValidatorTest extends TestCase
{
    private ThemeTokenValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ThemeTokenValidator();
    }

    // ── Color tokens ─────────────────────────────────────────────────────────

    public function testValidSixDigitHexColorIsAccepted(): void
    {
        $result = $this->validator->validatePatch(['--hm-bg' => '#1a1a2e']);
        self::assertSame(['--hm-bg' => '#1a1a2e'], $result);
    }

    public function testColorIsCaseInsensitive(): void
    {
        $result = $this->validator->validatePatch(['--hm-ink' => '#AABBCC']);
        self::assertSame(['--hm-ink' => '#AABBCC'], $result);
    }

    public function testThreeDigitHexIsRejected(): void
    {
        $result = $this->validator->validatePatch(['--hm-bg' => '#abc']);
        self::assertSame([], $result);
    }

    public function testRgbColorValueIsRejected(): void
    {
        $result = $this->validator->validatePatch(['--hm-bg' => 'rgb(0,0,0)']);
        self::assertSame([], $result);
    }

    public function testCssInjectionInColorIsRejected(): void
    {
        $result = $this->validator->validatePatch(['--hm-bg' => 'red; color: blue']);
        self::assertSame([], $result);
    }

    public function testJavascriptInjectionInColorIsRejected(): void
    {
        $result = $this->validator->validatePatch(['--hm-bg' => 'javascript:alert(1)']);
        self::assertSame([], $result);
    }

    public function testAllColorTokensAcceptValidHex(): void
    {
        $patch = [
            '--hm-bg'               => '#0c0c14',
            '--hm-ink'              => '#e8e4ff',
            '--hm-accent-primary'   => '#7c3aed',
            '--hm-accent-secondary' => '#10b981',
            '--hm-slide-bg'         => '#0c0c14',
            '--hm-slide-fg'         => '#e8e4ff',
        ];

        $result = $this->validator->validatePatch($patch);
        self::assertSame($patch, $result);
    }

    // ── Font family tokens ────────────────────────────────────────────────────

    public function testValidFontFamilyIsAccepted(): void
    {
        foreach (ThemeTokenValidator::FONT_FAMILY_OPTIONS as $family) {
            $result = $this->validator->validatePatch(['--hm-font-body' => $family]);
            self::assertSame(['--hm-font-body' => $family], $result, "Font family '$family' should be accepted");
        }
    }

    public function testArbitraryFontFamilyIsRejected(): void
    {
        $result = $this->validator->validatePatch(['--hm-font-body' => 'Comic Sans, cursive; color:red']);
        self::assertSame([], $result);
    }

    public function testUnknownFontFamilyIsRejected(): void
    {
        $result = $this->validator->validatePatch(['--hm-font-title' => 'Papyrus, fantasy']);
        self::assertSame([], $result);
    }

    // ── Font weight tokens ────────────────────────────────────────────────────

    public function testValidFontWeightIsAccepted(): void
    {
        foreach (ThemeTokenValidator::FONT_WEIGHT_OPTIONS as $weight) {
            $result = $this->validator->validatePatch(['--hm-font-weight-bold' => $weight]);
            self::assertSame(['--hm-font-weight-bold' => $weight], $result);
        }
    }

    public function testArbitraryFontWeightIsRejected(): void
    {
        $result = $this->validator->validatePatch(['--hm-font-weight-bold' => '900; color:red']);
        self::assertSame([], $result);
    }

    public function testNonNumericFontWeightIsRejected(): void
    {
        $result = $this->validator->validatePatch(['--hm-font-weight-bold' => 'bold']);
        self::assertSame([], $result);
    }

    // ── Letter spacing tokens ─────────────────────────────────────────────────

    public function testValidLetterSpacingIsAccepted(): void
    {
        $cases = ['0.12em', '-0.02em', '0.00em', '0.30em', '-0.05em'];

        foreach ($cases as $value) {
            $result = $this->validator->validatePatch(['--hm-letter-spacing-label' => $value]);
            self::assertSame(['--hm-letter-spacing-label' => $value], $result, "Letter spacing '$value' should be accepted");
        }
    }

    public function testLetterSpacingWithoutEmUnitIsRejected(): void
    {
        $result = $this->validator->validatePatch(['--hm-letter-spacing-label' => '0.12']);
        self::assertSame([], $result);
    }

    public function testLetterSpacingInjectionIsRejected(): void
    {
        $result = $this->validator->validatePatch(['--hm-letter-spacing-label' => '0.12em; color: red']);
        self::assertSame([], $result);
    }

    public function testLetterSpacingInPxIsRejected(): void
    {
        $result = $this->validator->validatePatch(['--hm-letter-spacing-tight' => '2px']);
        self::assertSame([], $result);
    }

    // ── Font size tokens ──────────────────────────────────────────────────────

    public function testValidFontSizeTitleIsAccepted(): void
    {
        foreach (ThemeTokenValidator::FONT_SIZE_TITLE_OPTIONS as $size) {
            $result = $this->validator->validatePatch(['--hm-font-size-title' => $size]);
            self::assertSame(['--hm-font-size-title' => $size], $result, "Title size '$size' should be accepted");
        }
    }

    public function testArbitraryFontSizeTitleIsRejected(): void
    {
        $result = $this->validator->validatePatch(['--hm-font-size-title' => '999rem; color:red']);
        self::assertSame([], $result);
    }

    public function testValidFontSizeSubtitleIsAccepted(): void
    {
        foreach (ThemeTokenValidator::FONT_SIZE_SUBTITLE_OPTIONS as $size) {
            $result = $this->validator->validatePatch(['--hm-font-size-subtitle' => $size]);
            self::assertSame(['--hm-font-size-subtitle' => $size], $result);
        }
    }

    // ── Unknown tokens ────────────────────────────────────────────────────────

    public function testTokenWithoutHmPrefixIsDropped(): void
    {
        $result = $this->validator->validatePatch(['color' => '#ff0000']);
        self::assertSame([], $result);
    }

    public function testCustomNonHmTokenIsDropped(): void
    {
        $result = $this->validator->validatePatch(['--custom-var' => '#ff0000']);
        self::assertSame([], $result);
    }

    public function testUnknownHmTokenIsDropped(): void
    {
        $result = $this->validator->validatePatch(['--hm-unknown-var' => '#ff0000']);
        self::assertSame([], $result);
    }

    // ── Mixed patch ───────────────────────────────────────────────────────────

    public function testMixedPatchRetainsOnlyValidEntries(): void
    {
        $patch = [
            '--hm-bg'           => '#0c0c14',          // valid color
            '--hm-ink'          => 'red; bad',          // invalid color value
            '--hm-font-body'    => 'Georgia, "Times New Roman", serif', // valid font
            '--hm-font-title'   => 'Papyrus, fantasy',  // invalid font
            '--hm-unknown'      => '#ff0000',            // unknown token
            'background'        => '#ff0000',            // no --hm- prefix
        ];

        $result = $this->validator->validatePatch($patch);

        self::assertSame([
            '--hm-bg'        => '#0c0c14',
            '--hm-font-body' => 'Georgia, "Times New Roman", serif',
        ], $result);
    }

    // ── Edge cases ────────────────────────────────────────────────────────────

    public function testEmptyPatchReturnsEmpty(): void
    {
        self::assertSame([], $this->validator->validatePatch([]));
    }

    public function testEmptyStringValueIsDropped(): void
    {
        $result = $this->validator->validatePatch(['--hm-bg' => '']);
        self::assertSame([], $result);
    }

    public function testNonStringKeyIsDropped(): void
    {
        $result = $this->validator->validatePatch([0 => '#ff0000']);
        self::assertSame([], $result);
    }

    public function testNonStringValueIsDropped(): void
    {
        $result = $this->validator->validatePatch(['--hm-bg' => ['#ff0000']]);
        self::assertSame([], $result);
    }

    // ── Static helpers ────────────────────────────────────────────────────────

    public function testAllowedTokenNamesIncludesColorTokens(): void
    {
        $names = ThemeTokenValidator::allowedTokenNames();
        self::assertContains('--hm-bg', $names);
        self::assertContains('--hm-ink', $names);
        self::assertContains('--hm-accent-primary', $names);
    }

    public function testAllowedTokenNamesIncludesTypoTokens(): void
    {
        $names = ThemeTokenValidator::allowedTokenNames();
        self::assertContains('--hm-font-body', $names);
        self::assertContains('--hm-font-title', $names);
        self::assertContains('--hm-font-weight-bold', $names);
        self::assertContains('--hm-letter-spacing-label', $names);
        self::assertContains('--hm-font-size-title', $names);
    }
}
