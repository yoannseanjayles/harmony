<?php

namespace App\Theme;

use App\Entity\Project;
use App\Entity\Slide;
use App\Entity\Theme;
/**
 * T181 — ThemeEngine: applies a Theme preset to a project and generates the CSS override block
 * that is injected into each slide's rendered HTML.
 *
 * Design:
 *   - toCssBlock()          → pure function: JSON tokens → <style>:root{...}</style> snippet
 *   - applyPresetToProject() → mutates Project::themeConfigJson and invalidates all slide caches
 *     so that the next SlideBuilder::buildSlide() call triggers a full re-render (T183).
 *
 * Security:
 *   Only keys prefixed with "--hm-" are written into the CSS block to prevent injection of
 *   arbitrary CSS properties.  Values are not further sanitised here because they originate
 *   exclusively from the controlled preset JSON files and are rendered inside a <style> tag
 *   that is already contained within each slide's isolated HTML snippet.
 */
final class ThemeEngine
{
    /**
     * Generate a <style>:root{…}</style> block from a JSON token map.
     *
     * Returns an empty string when the token map is empty or contains no valid --hm- keys,
     * so callers can safely concatenate without adding empty style tags.
     *
     * When the map contains `animationsEnabled = '0'` the output includes an additional
     * CSS rule that suppresses all decorative animation classes, ensuring that HTML exports
     * produced from the cached slide HTML also honour the "no animations" setting (T200).
     *
     * @param string $tokensJson Raw JSON string — e.g. Project::getThemeConfigJson()
     */
    public function toCssBlock(string $tokensJson): string
    {
        if ($tokensJson === '' || $tokensJson === '{}') {
            return '';
        }

        try {
            $tokens = json_decode($tokensJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }

        if (!is_array($tokens) || $tokens === []) {
            return '';
        }

        $declarations = '';
        foreach ($tokens as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, '--hm-')) {
                continue;
            }
            if (!is_string($value) || $value === '') {
                continue;
            }
            $declarations .= sprintf('%s:%s;', $key, $value);
        }

        if ($declarations === '') {
            return '';
        }

        $css = sprintf('<style>:root{%s}', $declarations);

        // T200 — When animations are globally disabled, append class-based suppression rules so
        // that exported slide HTML snippets (which contain their own style block) also respect the
        // setting, regardless of the presence of the .hm-no-animation container class.
        if (isset($tokens[ThemeTokenValidator::ANIM_ENABLED_KEY]) && $tokens[ThemeTokenValidator::ANIM_ENABLED_KEY] === '0') {
            $css .= '.hm-anim-fade-in,.hm-anim-slide-up,.hm-anim-phone-in,'
                  . '.hm-anim-float,.hm-anim-text-in,.hm-anim-line-pop{animation:none!important}'
                  . '.hm-anim-glint{animation:none!important;background:none!important}';
        }

        $css .= '</style>';

        return $css;
    }

    /**
     * T181 / T183 — Apply a preset's token overrides to a project and invalidate every
     * associated slide's render cache so the next render picks up the new theme.
     *
     * The caller is responsible for flushing the EntityManager after this call.
     *
     * @param list<Slide> $slides All slides belonging to $project (order does not matter).
     */
    public function applyPresetToProject(Theme $preset, Project $project, array $slides): void
    {
        try {
            $tokens = json_decode($preset->getTokensJson(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $tokens = [];
        }

        $project->setThemeConfig(is_array($tokens) ? $tokens : []);

        foreach ($slides as $slide) {
            if ($slide instanceof Slide) {
                $slide->invalidateRenderCache();
            }
        }
    }

    /**
     * T190 / T191 — Merge a validated token patch onto the project's theme config and
     * invalidate every associated slide's render cache (T191).
     *
     * The patch is validated by ThemeTokenValidator before being merged so that only
     * allow-listed --hm-* tokens with safe values reach the project config.
     *
     * The caller is responsible for flushing the EntityManager after this call.
     *
     * @param array<string, mixed> $tokenPatch Raw token patch from the request.
     * @param list<Slide>          $slides     All slides belonging to $project.
     *
     * @return array<string, string> The validated subset that was actually merged.
     */
    public function mergeTokenOverrides(
        array $tokenPatch,
        Project $project,
        array $slides,
        ThemeTokenValidator $validator,
    ): array {
        $validated = $validator->validatePatch($tokenPatch);

        if ($validated === []) {
            return [];
        }

        $existing = $project->getThemeConfig();
        $merged = array_merge($existing, $validated);
        $project->setThemeConfig($merged);

        foreach ($slides as $slide) {
            if ($slide instanceof Slide) {
                $slide->invalidateRenderCache();
            }
        }

        return $validated;
    }

    /**
     * Return the list of built-in preset names.
     *
     * @return list<string>
     */
    public static function presetNames(): array
    {
        return ['cinematic', 'corporate', 'epure'];
    }
}
