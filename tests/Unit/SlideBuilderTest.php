<?php

namespace App\Tests\Unit;

use App\Entity\Project;
use App\Entity\Slide;
use App\Slide\SlideBuilder;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class SlideBuilderTest extends TestCase
{
    private SlideBuilder $builder;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 2).'/templates');
        $twig = new Environment($loader, ['autoescape' => 'html']);
        $this->builder = new SlideBuilder($twig);
    }

    // ── title slide ──────────────────────────────────────────────────────────

    public function testBuildsTitleSlideWithAllFields(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TITLE, [
            'label' => 'Keynote 2025',
            'title' => 'Harmony Platform',
            'subtitle' => 'AI-powered presentations',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Keynote 2025', $html);
        self::assertStringContainsString('Harmony Platform', $html);
        self::assertStringContainsString('AI-powered presentations', $html);
        self::assertStringContainsString('hm-slide--title', $html);
        self::assertStringContainsString('data-slide-type="title"', $html);
    }

    public function testBuildsTitleSlideWithoutOptionalFields(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TITLE, [
            'title' => 'Minimal Title',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Minimal Title', $html);
        self::assertStringNotContainsString('class="hm-slide__label"', $html);
        self::assertStringNotContainsString('class="hm-slide__subtitle"', $html);
    }

    public function testTitleSlideEscapesHtmlInContent(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TITLE, [
            'label' => '<script>alert("xss")</script>',
            'title' => '<b>Bold & safe</b>',
            'subtitle' => '"quoted" text',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>Bold', $html);
        self::assertStringContainsString('&lt;b&gt;Bold', $html);
    }

    // ── content slide ─────────────────────────────────────────────────────────

    public function testBuildsContentSlideWithTitleBodyAndItems(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_CONTENT, [
            'title' => 'Key Benefits',
            'body' => 'Our platform delivers results.',
            'items' => ['Fast', 'Reliable', 'Scalable'],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Key Benefits', $html);
        self::assertStringContainsString('Our platform delivers results.', $html);
        self::assertStringContainsString('Fast', $html);
        self::assertStringContainsString('Reliable', $html);
        self::assertStringContainsString('Scalable', $html);
        self::assertStringContainsString('hm-slide--content', $html);
        self::assertStringContainsString('data-slide-type="content"', $html);
    }

    public function testBuildsContentSlideWithTitleOnly(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_CONTENT, [
            'title' => 'Simple Content',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Simple Content', $html);
        self::assertStringNotContainsString('class="hm-slide__body"', $html);
        self::assertStringNotContainsString('class="hm-slide__list"', $html);
    }

    public function testContentSlideEscapesHtmlInItems(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_CONTENT, [
            'title' => 'List Slide',
            'items' => ['Normal item', '<script>evil()</script>'],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testContentSlideFiltersEmptyItems(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_CONTENT, [
            'title' => 'Filtered',
            'items' => ['First', '', '  ', 'Last'],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('First', $html);
        self::assertStringContainsString('Last', $html);
        $count = substr_count($html, '<li ');
        self::assertSame(2, $count);
    }

    // ── closing slide ─────────────────────────────────────────────────────────

    public function testBuildsClosingSlideWithMessageAndCta(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_CLOSING, [
            'message' => 'Thank you for your attention.',
            'cta_label' => 'Get started',
            'cta_url' => 'https://harmony.app',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Thank you for your attention.', $html);
        self::assertStringContainsString('Get started', $html);
        self::assertStringContainsString('href="https://harmony.app"', $html);
        self::assertStringContainsString('hm-slide--closing', $html);
        self::assertStringContainsString('data-slide-type="closing"', $html);
    }

    public function testBuildsClosingSlideWithMessageOnly(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_CLOSING, [
            'message' => 'See you next time.',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('See you next time.', $html);
        self::assertStringNotContainsString('class="hm-slide__cta"', $html);
    }

    public function testClosingSlideRendersCtaWithoutUrlAsSpan(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_CLOSING, [
            'message' => 'Closing message.',
            'cta_label' => 'Contact us',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Contact us', $html);
        self::assertStringNotContainsString('<a ', $html);
        self::assertStringContainsString('<span class="hm-slide__cta-link">', $html);
    }

    public function testClosingSlideEscapesHtml(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_CLOSING, [
            'message' => '<script>bad()</script>',
            'cta_label' => '<b>Click</b>',
            'cta_url' => 'https://safe.example',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>Click', $html);
    }

    public function testClosingSlideRejectsJavascriptProtocolUrls(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_CLOSING, [
            'message' => 'Done.',
            'cta_label' => 'Click',
            'cta_url' => 'javascript:alert(1)',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('href=', $html);
    }

    // ── fallback ──────────────────────────────────────────────────────────────

    public function testUnknownTypeRendersContentTemplate(): void
    {
        $slide = $this->makeSlide('unknown_type', [
            'title' => 'Fallback',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('hm-slide--content', $html);
    }

    // ── CSS tokens ────────────────────────────────────────────────────────────

    public function testTemplatesUseHmCssTokensAndNotInlineColors(): void
    {
        foreach ([Slide::TYPE_TITLE, Slide::TYPE_CONTENT, Slide::TYPE_CLOSING] as $type) {
            $slide = $this->makeSlide($type, ['title' => 'T', 'message' => 'M']);
            $html = $this->builder->buildSlide($slide);

            self::assertStringContainsString('--hm-', $html, "Template for type '{$type}' should use --hm- CSS tokens");
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $content
     */
    private function makeSlide(string $type, array $content, int $position = 1): Slide
    {
        $project = new Project();
        $project->setTitle('Test Project');

        $slide = new Slide();
        $slide->setProject($project);
        $slide->setType($type);
        $slide->setContent($content);
        $slide->setPosition($position);

        return $slide;
    }
}
