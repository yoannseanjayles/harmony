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
        // Reduced (but not zero) animation duration
        $duration = $tokens['--hm-anim-duration'] ?? '';
        self::assertNotSame('0.55s', $duration, 'Corporate should have reduced animation duration vs cinematic');
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
}
