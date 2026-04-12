<?php

namespace App\Tests\Unit;

use App\Entity\Project;
use App\Entity\Slide;
use App\Entity\Theme;
use App\Theme\ThemeEngine;
use App\Theme\ThemePresetLoader;
use PHPUnit\Framework\TestCase;

/**
 * T184 — Unit tests for ThemeEngine covering each preset.
 */
final class ThemeEngineTest extends TestCase
{
    private ThemeEngine $engine;
    private ThemePresetLoader $loader;

    protected function setUp(): void
    {
        $this->engine = new ThemeEngine();
        $this->loader = new ThemePresetLoader();
    }

    // ── toCssBlock ──────────────────────────────────────────────────────────

    public function testToCssBlockReturnsEmptyForEmptyJson(): void
    {
        self::assertSame('', $this->engine->toCssBlock('{}'));
        self::assertSame('', $this->engine->toCssBlock(''));
    }

    public function testToCssBlockReturnsEmptyForMalformedJson(): void
    {
        self::assertSame('', $this->engine->toCssBlock('not-json'));
    }

    public function testToCssBlockReturnsEmptyForNonObjectJson(): void
    {
        self::assertSame('', $this->engine->toCssBlock('[1,2,3]'));
    }

    public function testToCssBlockFiltersNonHmKeys(): void
    {
        $json = json_encode([
            '--hm-bg' => '#fff',
            'color'   => 'red',     // not --hm- prefixed → stripped
            '--custom' => 'blue',   // not --hm- prefixed → stripped
        ]);

        $css = $this->engine->toCssBlock((string) $json);

        self::assertStringContainsString('--hm-bg:#fff', $css);
        self::assertStringNotContainsString('color:red', $css);
        self::assertStringNotContainsString('--custom:blue', $css);
    }

    public function testToCssBlockWrapsInStyleTag(): void
    {
        $json = json_encode(['--hm-bg' => '#0c0c14']);
        $css = $this->engine->toCssBlock((string) $json);

        self::assertStringStartsWith('<style>', $css);
        self::assertStringEndsWith('</style>', $css);
        self::assertStringContainsString(':root{', $css);
    }

    public function testToCssBlockSkipsEmptyValues(): void
    {
        $json = json_encode(['--hm-bg' => '', '--hm-ink' => '#fff']);
        $css = $this->engine->toCssBlock((string) $json);

        self::assertStringNotContainsString('--hm-bg', $css);
        self::assertStringContainsString('--hm-ink:#fff', $css);
    }

    // ── preset JSON files ────────────────────────────────────────────────────

    public function testCinematicPresetLoads(): void
    {
        $theme = $this->loader->load('cinematic');

        self::assertSame('theme.preset.cinematic', $theme->getName());
        self::assertTrue($theme->isPreset());
        self::assertNull($theme->getUser());

        $tokens = $theme->getTokens();
        self::assertArrayHasKey('--hm-bg', $tokens);
        self::assertArrayHasKey('--hm-ink', $tokens);
        self::assertArrayHasKey('--hm-accent-primary', $tokens);
        self::assertArrayHasKey('--hm-anim-duration', $tokens);
    }

    public function testCorporatePresetLoads(): void
    {
        $theme = $this->loader->load('corporate');

        self::assertSame('theme.preset.corporate', $theme->getName());
        self::assertTrue($theme->isPreset());

        $tokens = $theme->getTokens();
        // Corporate uses neutral slate tones
        self::assertStringContainsString('334155', $tokens['--hm-accent-primary'] ?? '');
        // Reduced animation duration (0.30s vs cinematic's 0.55s)
        self::assertSame('0.30s', $tokens['--hm-anim-duration'] ?? '');
    }

    public function testEpurePresetLoads(): void
    {
        $theme = $this->loader->load('epure');

        self::assertSame('theme.preset.epure', $theme->getName());
        self::assertTrue($theme->isPreset());

        $tokens = $theme->getTokens();
        // Épuré uses light background
        self::assertSame('#ffffff', $tokens['--hm-bg'] ?? '');
        self::assertSame('#ffffff', $tokens['--hm-slide-bg'] ?? '');
        // Animations are disabled (duration = 0s)
        self::assertSame('0s', $tokens['--hm-anim-duration'] ?? '');
    }

    public function testEachPresetProducesValidCssBlock(): void
    {
        foreach (ThemeEngine::presetNames() as $name) {
            $theme = $this->loader->load($name);
            $css = $this->engine->toCssBlock($theme->getTokensJson());

            self::assertNotSame('', $css, sprintf('Preset "%s" should produce a non-empty CSS block', $name));
            self::assertStringStartsWith('<style>', $css);
            self::assertStringContainsString(':root{', $css);
            self::assertStringContainsString('--hm-bg', $css);
        }
    }

    public function testUnknownPresetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->loader->load('unknown');
    }

    // ── applyPresetToProject ─────────────────────────────────────────────────

    public function testApplyPresetSetsProjectThemeConfig(): void
    {
        $theme = $this->loader->load('cinematic');
        $project = new Project();
        $project->setTitle('Test');

        $this->engine->applyPresetToProject($theme, $project, []);

        $config = $project->getThemeConfig();
        self::assertArrayHasKey('--hm-bg', $config);
        self::assertSame('#0c0c14', $config['--hm-bg']);
    }

    public function testApplyPresetInvalidatesAllSlideRenderCaches(): void
    {
        $theme = $this->loader->load('corporate');
        $project = new Project();
        $project->setTitle('Test');

        $slide1 = (new Slide())->setProject($project)->setRenderHash('abc')->setHtmlCache('<p>cached</p>');
        $slide2 = (new Slide())->setProject($project)->setRenderHash('def')->setHtmlCache('<p>also cached</p>');

        $this->engine->applyPresetToProject($theme, $project, [$slide1, $slide2]);

        self::assertNull($slide1->getRenderHash(), 'Slide 1 renderHash should be nulled');
        self::assertNull($slide1->getHtmlCache(), 'Slide 1 htmlCache should be nulled');
        self::assertNull($slide2->getRenderHash(), 'Slide 2 renderHash should be nulled');
        self::assertNull($slide2->getHtmlCache(), 'Slide 2 htmlCache should be nulled');
    }

    public function testApplyPresetToProjectWithNoSlides(): void
    {
        $theme = $this->loader->load('epure');
        $project = new Project();
        $project->setTitle('Test');

        // Should not throw
        $this->engine->applyPresetToProject($theme, $project, []);

        $config = $project->getThemeConfig();
        self::assertArrayHasKey('--hm-bg', $config);
    }

    public function testCinematicCssBlockContainsDarkBackground(): void
    {
        $theme = $this->loader->load('cinematic');
        $css = $this->engine->toCssBlock($theme->getTokensJson());

        self::assertStringContainsString('--hm-bg:#0c0c14', $css);
    }

    public function testCorporateCssBlockContainsLightBackground(): void
    {
        $theme = $this->loader->load('corporate');
        $css = $this->engine->toCssBlock($theme->getTokensJson());

        self::assertStringContainsString('--hm-bg:#f4f4f6', $css);
    }

    public function testEpureCssBlockContainsWhiteBackground(): void
    {
        $theme = $this->loader->load('epure');
        $css = $this->engine->toCssBlock($theme->getTokensJson());

        self::assertStringContainsString('--hm-bg:#ffffff', $css);
    }

    public function testPresetNamesReturnsThreeEntries(): void
    {
        self::assertSame(['cinematic', 'corporate', 'epure'], ThemeEngine::presetNames());
    }

    // ── Animation settings (T193–T200) ───────────────────────────────────────

    public function testToCssBlockOmitsNonHmKeyForAnimationsEnabled(): void
    {
        $json = json_encode([
            '--hm-bg'          => '#0c0c14',
            'animationsEnabled' => '1',
        ]);
        $css = $this->engine->toCssBlock((string) $json);

        self::assertStringContainsString('--hm-bg:#0c0c14', $css);
        self::assertStringNotContainsString('animationsEnabled', $css);
    }

    public function testToCssBlockEmitsAnimationSuppressionWhenDisabled(): void
    {
        $json = json_encode([
            '--hm-bg'          => '#0c0c14',
            'animationsEnabled' => '0',
        ]);
        $css = $this->engine->toCssBlock((string) $json);

        self::assertStringContainsString('animation:none!important', $css);
        self::assertStringNotContainsString('animationsEnabled', $css);
    }

    public function testToCssBlockDoesNotEmitSuppressionWhenEnabled(): void
    {
        $json = json_encode([
            '--hm-bg'          => '#0c0c14',
            'animationsEnabled' => '1',
        ]);
        $css = $this->engine->toCssBlock((string) $json);

        self::assertStringNotContainsString('animation:none', $css);
    }

    public function testToCssBlockDoesNotEmitSuppressionWhenKeyAbsent(): void
    {
        $json = json_encode(['--hm-bg' => '#0c0c14']);
        $css  = $this->engine->toCssBlock((string) $json);

        self::assertStringNotContainsString('animation:none', $css);
    }

    public function testMergeTokenOverridesPersistsAnimationSettings(): void
    {
        $validator = new \App\Theme\ThemeTokenValidator();
        $project   = new Project();
        $project->setTitle('Test');

        $this->engine->mergeTokenOverrides(
            [
                '--hm-anim-duration'  => '0.9s',
                '--hm-anim-intensity' => '0.4',
                'animationsEnabled'   => '0',
            ],
            $project,
            [],
            $validator,
        );

        // T201 — overrides stored in themeOverridesJson, not themeConfigJson
        $overrides = $project->getThemeOverrides();
        self::assertSame('0.9s',  $overrides['--hm-anim-duration']);
        self::assertSame('0.4',   $overrides['--hm-anim-intensity']);
        self::assertSame('0',     $overrides['animationsEnabled']);
    }

    // ── T201 / T202 — Preset + overrides merging ────────────────────────────

    public function testMergeTokenOverridesStoresDeltaInThemeOverridesJson(): void
    {
        $validator = new \App\Theme\ThemeTokenValidator();
        $project   = new Project();
        $project->setTitle('Test');

        // Apply cinematic preset as base first
        $preset = $this->loader->load('cinematic');
        $this->engine->applyPresetToProject($preset, $project, []);

        $baseBg = $project->getThemeConfig()['--hm-bg'];

        // Merge a user override
        $this->engine->mergeTokenOverrides(
            ['--hm-bg' => '#ff0000'],
            $project,
            [],
            $validator,
        );

        // Base preset is unchanged in themeConfigJson
        self::assertSame($baseBg, $project->getThemeConfig()['--hm-bg'],
            'Base preset in themeConfigJson must not be modified by overrides');

        // Override is stored in themeOverridesJson
        self::assertSame('#ff0000', $project->getThemeOverrides()['--hm-bg']);

        // Effective theme merges both
        $effective = $project->getEffectiveThemeConfig();
        self::assertSame('#ff0000', $effective['--hm-bg'],
            'Effective theme must reflect the user override');
    }

    public function testEffectiveThemeEqualsBaseWhenNoOverrides(): void
    {
        $preset  = $this->loader->load('cinematic');
        $project = new Project();
        $project->setTitle('Test');

        $this->engine->applyPresetToProject($preset, $project, []);

        self::assertSame(
            $project->getThemeConfig(),
            $project->getEffectiveThemeConfig(),
            'Effective theme must equal the preset base when there are no overrides',
        );
    }

    public function testOverridesAccumulateAcrossMultipleCalls(): void
    {
        $validator = new \App\Theme\ThemeTokenValidator();
        $project   = new Project();
        $project->setTitle('Test');

        $this->engine->applyPresetToProject($this->loader->load('cinematic'), $project, []);

        $this->engine->mergeTokenOverrides(['--hm-bg' => '#aabbcc'], $project, [], $validator);
        $this->engine->mergeTokenOverrides(['--hm-ink' => '#112233'], $project, [], $validator);

        $overrides = $project->getThemeOverrides();
        self::assertSame('#aabbcc', $overrides['--hm-bg']);
        self::assertSame('#112233', $overrides['--hm-ink']);

        $effective = $project->getEffectiveThemeConfig();
        self::assertSame('#aabbcc', $effective['--hm-bg']);
        self::assertSame('#112233', $effective['--hm-ink']);
    }

    // ── T203 — Theme version counter ─────────────────────────────────────────

    public function testApplyPresetIncrementsThemeVersion(): void
    {
        $preset  = $this->loader->load('cinematic');
        $project = new Project();
        $project->setTitle('Test');

        $vBefore = $project->getThemeVersion();
        $this->engine->applyPresetToProject($preset, $project, []);

        self::assertSame($vBefore + 1, $project->getThemeVersion());
    }

    public function testMergeTokenOverridesIncrementsThemeVersion(): void
    {
        $validator = new \App\Theme\ThemeTokenValidator();
        $project   = new Project();
        $project->setTitle('Test');

        $vBefore = $project->getThemeVersion();
        $this->engine->mergeTokenOverrides(['--hm-bg' => '#ffffff'], $project, [], $validator);

        self::assertSame($vBefore + 1, $project->getThemeVersion());
    }

    public function testMergeTokenOverridesDoesNotIncrementVersionWhenNothingValid(): void
    {
        $validator = new \App\Theme\ThemeTokenValidator();
        $project   = new Project();
        $project->setTitle('Test');

        $vBefore = $project->getThemeVersion();
        $this->engine->mergeTokenOverrides(['invalid-key' => 'bad-value'], $project, [], $validator);

        self::assertSame($vBefore, $project->getThemeVersion());
    }

    // ── T205 — Reset overrides ────────────────────────────────────────────────

    public function testResetOverridesClearsThemeOverridesJson(): void
    {
        $validator = new \App\Theme\ThemeTokenValidator();
        $project   = new Project();
        $project->setTitle('Test');

        $this->engine->applyPresetToProject($this->loader->load('cinematic'), $project, []);
        $this->engine->mergeTokenOverrides(['--hm-bg' => '#ff0000'], $project, [], $validator);

        self::assertNotEmpty($project->getThemeOverrides(), 'Pre-condition: overrides must be set');

        $this->engine->resetOverrides($project, []);

        self::assertSame([], $project->getThemeOverrides(), 'Overrides must be empty after reset');
    }

    public function testResetOverridesIncrementsThemeVersion(): void
    {
        $project = new Project();
        $project->setTitle('Test');

        $vBefore = $project->getThemeVersion();
        $this->engine->resetOverrides($project, []);

        self::assertSame($vBefore + 1, $project->getThemeVersion());
    }

    public function testResetOverridesInvalidatesSlideRenderCache(): void
    {
        $project = new Project();
        $project->setTitle('Test');

        $slide = (new Slide())->setProject($project)->setRenderHash('abc')->setHtmlCache('<p>cached</p>');

        $this->engine->resetOverrides($project, [$slide]);

        self::assertNull($slide->getRenderHash());
        self::assertNull($slide->getHtmlCache());
    }

    public function testApplyPresetClearsExistingOverrides(): void
    {
        $validator = new \App\Theme\ThemeTokenValidator();
        $project   = new Project();
        $project->setTitle('Test');

        $this->engine->mergeTokenOverrides(['--hm-bg' => '#ff0000'], $project, [], $validator);
        self::assertNotEmpty($project->getThemeOverrides(), 'Pre-condition: overrides must be set');

        // Re-applying a preset must clear user overrides
        $this->engine->applyPresetToProject($this->loader->load('corporate'), $project, []);

        self::assertSame([], $project->getThemeOverrides(),
            'applyPresetToProject must reset themeOverridesJson to empty');
    }

    // ── T206 — Reproducibility ────────────────────────────────────────────────

    public function testTwoCssBlockCallsWithSameTokensProduceDidenticalOutput(): void
    {
        $preset  = $this->loader->load('cinematic');
        $project = new Project();
        $project->setTitle('Test');
        $this->engine->applyPresetToProject($preset, $project, []);

        $cssA = $this->engine->toCssBlock($project->getEffectiveThemeConfigJson());
        $cssB = $this->engine->toCssBlock($project->getEffectiveThemeConfigJson());

        self::assertSame($cssA, $cssB,
            'Two consecutive calls with the same tokens must produce identical CSS output');
    }

    // ── T208 — Visual regression: cinematic preset CSS snapshot ─────────────

    public function testCinematicPresetCssBlockContainsExpectedTokens(): void
    {
        $preset  = $this->loader->load('cinematic');
        $project = new Project();
        $project->setTitle('Test');
        $this->engine->applyPresetToProject($preset, $project, []);

        $css = $this->engine->toCssBlock($project->getEffectiveThemeConfigJson());

        // Colour tokens
        self::assertStringContainsString('--hm-bg:#0c0c14',        $css);
        self::assertStringContainsString('--hm-ink:#e8e4ff',       $css);
        self::assertStringContainsString('--hm-accent-primary:#7c3aed', $css);
        self::assertStringContainsString('--hm-accent-secondary:#10b981', $css);
        self::assertStringContainsString('--hm-slide-bg:#0c0c14',  $css);
        self::assertStringContainsString('--hm-slide-fg:#e8e4ff',  $css);
        // Animation defaults
        self::assertStringContainsString('--hm-anim-duration:0.55s', $css);
        // Wrapped in a <style>:root{} block
        self::assertStringStartsWith('<style>',   $css);
        self::assertStringEndsWith('</style>',   $css);
        self::assertStringContainsString(':root{', $css);
    }

    public function testCinematicPresetCssBlockDoesNotContainNonHmKeys(): void
    {
        $preset  = $this->loader->load('cinematic');
        $project = new Project();
        $project->setTitle('Test');
        $this->engine->applyPresetToProject($preset, $project, []);

        $css = $this->engine->toCssBlock($project->getEffectiveThemeConfigJson());

        // Only --hm-* keys must appear; no bare property names
        self::assertStringNotContainsString('animationsEnabled', $css);
    }

    public function testCinematicOverrideOnlyChangesOverriddenToken(): void
    {
        $validator = new \App\Theme\ThemeTokenValidator();
        $preset    = $this->loader->load('cinematic');
        $project   = new Project();
        $project->setTitle('Test');
        $this->engine->applyPresetToProject($preset, $project, []);

        // Override only the background colour
        $this->engine->mergeTokenOverrides(['--hm-bg' => '#123456'], $project, [], $validator);

        $css = $this->engine->toCssBlock($project->getEffectiveThemeConfigJson());

        self::assertStringContainsString('--hm-bg:#123456', $css,
            'Overridden token must appear in the CSS block');
        // Other cinematic tokens must be preserved unchanged
        self::assertStringContainsString('--hm-ink:#e8e4ff', $css,
            'Non-overridden tokens must remain from the preset base');
    }
}
