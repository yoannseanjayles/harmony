<?php

namespace App\Theme;

use App\Entity\Theme;

/**
 * T178 / T179 / T180 — Loads the built-in preset JSON files from disk and returns ready-to-use
 * Theme instances (not persisted — used as value objects when applying a preset to a project).
 *
 * Preset files live in src/Theme/presets/<name>.json.
 */
final class ThemePresetLoader
{
    private readonly string $presetsDir;

    public function __construct(?string $presetsDir = null)
    {
        $this->presetsDir = $presetsDir ?? __DIR__ . '/presets';
    }

    /**
     * Load a named preset and return a (non-persisted) Theme entity.
     *
     * @throws \InvalidArgumentException When the preset name is unknown.
     * @throws \RuntimeException         When the preset file cannot be read or decoded.
     */
    public function load(string $presetName): Theme
    {
        if (!in_array($presetName, ThemeEngine::presetNames(), true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown theme preset "%s". Valid presets: %s.',
                $presetName,
                implode(', ', ThemeEngine::presetNames()),
            ));
        }

        $path = $this->presetsDir . '/' . $presetName . '.json';

        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Theme preset file not found: %s', $path));
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException(sprintf('Could not read theme preset file: %s', $path));
        }

        try {
            json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf(
                'Theme preset file "%s" contains invalid JSON: %s',
                $path,
                $e->getMessage(),
            ));
        }

        $labelMap = [
            'cinematic' => 'theme.preset.cinematic',
            'corporate' => 'theme.preset.corporate',
            'epure'     => 'theme.preset.epure',
        ];

        return (new Theme())
            ->setName($labelMap[$presetName] ?? $presetName)
            ->setTokensJson(trim($json))
            ->setVersion(1)
            ->setIsPreset(true);
    }

    /**
     * @return array<string, Theme> Keyed by preset name.
     */
    public function loadAll(): array
    {
        $presets = [];
        foreach (ThemeEngine::presetNames() as $name) {
            $presets[$name] = $this->load($name);
        }

        return $presets;
    }
}
