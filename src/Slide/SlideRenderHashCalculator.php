<?php

namespace App\Slide;

/**
 * T160 — Calculates the deterministic renderHash for a slide.
 *
 * The hash is SHA-256 of the concatenation of:
 *   - normalised contentJson  (keys sorted recursively)
 *   - normalised themeJson    (keys sorted recursively)
 *   - templateVersion         (incremented when Twig templates change)
 *   - assetsVersion           (incremented when referenced static assets change)
 *
 * Normalising the JSON before hashing ensures that key ordering differences
 * (e.g. coming from different serialisers) do not produce false cache misses.
 */
final class SlideRenderHashCalculator
{
    public function __construct(
        private readonly string $templateVersion,
        private readonly string $assetsVersion,
    ) {
    }

    /**
     * Compute the renderHash for the given contentJson and themeJson strings.
     *
     * @param string $contentJson Raw JSON string stored in Slide::$contentJson.
     * @param string $themeJson   Raw JSON string from Project::getThemeConfigJson().
     */
    public function compute(string $contentJson, string $themeJson): string
    {
        $normalizedContent = $this->normalizeJson($contentJson);
        $normalizedTheme = $this->normalizeJson($themeJson);

        return hash(
            'sha256',
            $normalizedContent . $normalizedTheme . $this->templateVersion . $this->assetsVersion,
        );
    }

    /**
     * Return a canonical, key-sorted JSON representation.
     * Returns '{}' for any malformed, non-object, or empty input.
     */
    public function normalizeJson(string $json): string
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '{}';
        }

        // Treat empty arrays as empty objects; reject non-array values.
        if (!is_array($data) || $data === []) {
            return '{}';
        }

        $this->sortRecursively($data);

        try {
            return (string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return '{}';
        }
    }

    /**
     * @param array<mixed, mixed> $array
     */
    private function sortRecursively(array &$array): void
    {
        // Only sort associative arrays (JSON objects); preserve order of sequential lists.
        if (!array_is_list($array)) {
            ksort($array);
        }
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->sortRecursively($value);
            }
        }
    }
}
