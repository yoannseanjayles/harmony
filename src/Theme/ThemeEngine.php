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

        return sprintf('<style>:root{%s}</style>', $declarations);
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
     * Return the list of built-in preset names.
     *
     * @return list<string>
     */
    public static function presetNames(): array
    {
        return ['cinematic', 'corporate', 'epure'];
    }
}
