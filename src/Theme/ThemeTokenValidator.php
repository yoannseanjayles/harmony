<?php

namespace App\Theme;

/**
 * T185 / T186 / T187 / T188 — Server-side validation for theme token overrides sent by the
 * color and typography customization panel.
 *
 * Design:
 *   Only a known, finite set of --hm-* token names are accepted (allowlist).
 *   Each token category has its own value format check to prevent CSS injection.
 *
 * Security:
 *   - Unknown token names are silently dropped.
 *   - Values that do not match their category's pattern are silently dropped.
 *   - The allowlist is intentionally narrow: only the tokens exposed in the UI.
 */
final class ThemeTokenValidator
{
    /**
     * Color tokens — value must be a 6-digit hex color (#rrggbb) from the browser color picker.
     */
    private const COLOR_TOKENS = [
        '--hm-bg',
        '--hm-ink',
        '--hm-accent-primary',
        '--hm-accent-secondary',
        '--hm-slide-bg',
        '--hm-slide-fg',
    ];

    /**
     * Font-family tokens — value must be one of the allowed stacks.
     */
    private const FONT_FAMILY_TOKENS = [
        '--hm-font-body',
        '--hm-font-title',
    ];

    /**
     * Allowed font-family stacks (verbatim CSS values).
     */
    public const FONT_FAMILY_OPTIONS = [
        '"Inter", "Segoe UI", system-ui, sans-serif',
        'Georgia, "Times New Roman", serif',
        '"Helvetica Neue", Arial, sans-serif',
        '"JetBrains Mono", "Fira Code", monospace',
    ];

    /**
     * Font-weight tokens — value must be one of the allowed numeric weights.
     */
    private const FONT_WEIGHT_TOKENS = [
        '--hm-font-weight-bold',
        '--hm-font-weight-medium',
        '--hm-font-weight-normal',
    ];

    /**
     * Allowed font-weight values.
     */
    public const FONT_WEIGHT_OPTIONS = ['400', '500', '600', '700'];

    /**
     * Letter-spacing tokens — value must match N.NNem (with optional minus sign).
     */
    private const LETTER_SPACING_TOKENS = [
        '--hm-letter-spacing-label',
        '--hm-letter-spacing-tight',
    ];

    /**
     * Title font-size tokens — value must be one of the allowed clamp() expressions.
     */
    private const FONT_SIZE_TOKENS = [
        '--hm-font-size-title',
        '--hm-font-size-subtitle',
    ];

    /**
     * Animation duration token — value must be one of the allowed duration strings.
     * Covers slow / normal / fast presets and the "disabled" (0s) value.
     */
    private const ANIM_DURATION_TOKEN = '--hm-anim-duration';

    /**
     * Allowed animation duration values.
     * 0.25s = fast, 0.55s = normal, 0.9s = slow.
     * 0s matches the "épuré" preset convention (very short / no perceived motion)
     * and is kept so preset tokens pass validation.
     */
    public const ANIM_DURATION_OPTIONS = ['0s', '0.25s', '0.55s', '0.9s'];

    /**
     * Animation intensity token — value must be one of the allowed scale factors.
     */
    private const ANIM_INTENSITY_TOKEN = '--hm-anim-intensity';

    /**
     * Allowed intensity scale factors.
     * 0.4 = reduced, 1 = normal, 1.6 = max.
     */
    public const ANIM_INTENSITY_OPTIONS = ['0.4', '1', '1.6'];

    /**
     * Meta-key for the global animation on/off toggle.
     * Not a CSS custom property — filtered out by ThemeEngine::toCssBlock().
     * Stored alongside CSS tokens in the project themeConfig JSON.
     */
    public const ANIM_ENABLED_KEY = 'animationsEnabled';

    /**
     * Allowed title font-size values.
     */
    public const FONT_SIZE_TITLE_OPTIONS = [
        'clamp(1.8rem, 3.5vw, 3rem)',
        'clamp(2.4rem, 5vw, 4.2rem)',
        'clamp(3rem, 6vw, 5rem)',
    ];

    /**
     * Allowed subtitle font-size values.
     */
    public const FONT_SIZE_SUBTITLE_OPTIONS = [
        '0.95rem',
        '1.05rem',
        '1.2rem',
        '1.4rem',
    ];

    /**
     * Return only the valid entries from a flat token→value patch.
     *
     * Invalid token names and values that fail their category's validation are silently dropped.
     *
     * @param array<string, mixed> $patch Unsanitised token map from the request.
     *
     * @return array<string, string> Validated subset, ready to merge into the project config.
     */
    public function validatePatch(array $patch): array
    {
        $validated = [];

        foreach ($patch as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            $validatedValue = $this->validateToken($key, trim($value));
            if ($validatedValue !== null) {
                $validated[$key] = $validatedValue;
            }
        }

        return $validated;
    }

    /**
     * All customizable token names (union of all category allowlists).
     *
     * @return list<string>
     */
    public static function allowedTokenNames(): array
    {
        return array_merge(
            self::COLOR_TOKENS,
            self::FONT_FAMILY_TOKENS,
            self::FONT_WEIGHT_TOKENS,
            self::LETTER_SPACING_TOKENS,
            self::FONT_SIZE_TOKENS,
            [self::ANIM_DURATION_TOKEN, self::ANIM_INTENSITY_TOKEN, self::ANIM_ENABLED_KEY],
        );
    }

    private function validateToken(string $key, string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (in_array($key, self::COLOR_TOKENS, true)) {
            return $this->validateColor($value);
        }

        if (in_array($key, self::FONT_FAMILY_TOKENS, true)) {
            return $this->validateFontFamily($value);
        }

        if (in_array($key, self::FONT_WEIGHT_TOKENS, true)) {
            return $this->validateFontWeight($value);
        }

        if (in_array($key, self::LETTER_SPACING_TOKENS, true)) {
            return $this->validateLetterSpacing($value);
        }

        if ($key === '--hm-font-size-title') {
            return in_array($value, self::FONT_SIZE_TITLE_OPTIONS, true) ? $value : null;
        }

        if ($key === '--hm-font-size-subtitle') {
            return in_array($value, self::FONT_SIZE_SUBTITLE_OPTIONS, true) ? $value : null;
        }

        if ($key === self::ANIM_DURATION_TOKEN) {
            return in_array($value, self::ANIM_DURATION_OPTIONS, true) ? $value : null;
        }

        if ($key === self::ANIM_INTENSITY_TOKEN) {
            return in_array($value, self::ANIM_INTENSITY_OPTIONS, true) ? $value : null;
        }

        if ($key === self::ANIM_ENABLED_KEY) {
            return in_array($value, ['0', '1'], true) ? $value : null;
        }

        // Token not in any allowlist — drop.
        return null;
    }

    /**
     * Validate a 6-digit hex color as produced by <input type="color">.
     *
     * Accepts only #rrggbb format to keep the validation unambiguous and safe.
     * The 3-digit shorthand is intentionally excluded because browsers always expand it.
     */
    private function validateColor(string $value): ?string
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : null;
    }

    private function validateFontFamily(string $value): ?string
    {
        return in_array($value, self::FONT_FAMILY_OPTIONS, true) ? $value : null;
    }

    private function validateFontWeight(string $value): ?string
    {
        return in_array($value, self::FONT_WEIGHT_OPTIONS, true) ? $value : null;
    }

    /**
     * Validate a letter-spacing value: optional minus sign, 1 to 4 digits, optional decimal
     * point with 1 to 4 decimal digits, "em" unit — e.g. "0.12em", "-0.02em".
     */
    private function validateLetterSpacing(string $value): ?string
    {
        return (bool) preg_match('/^-?[0-9]{1,4}(\.[0-9]{1,4})?em$/', $value) ? $value : null;
    }
}
