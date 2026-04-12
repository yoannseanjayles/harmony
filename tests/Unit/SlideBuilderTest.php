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

    // ── split slide ───────────────────────────────────────────────────────────

    public function testBuildsSplitSlideWithAllFields(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_SPLIT, [
            'title' => 'How It Works',
            'body' => 'Three easy steps.',
            'items' => ['Step A', 'Step B'],
            'image_url' => 'https://example.com/img.jpg',
            'image_alt' => 'Diagram',
            'layout' => 'text-left',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('How It Works', $html);
        self::assertStringContainsString('Three easy steps.', $html);
        self::assertStringContainsString('Step A', $html);
        self::assertStringContainsString('Step B', $html);
        self::assertStringContainsString('src="https://example.com/img.jpg"', $html);
        self::assertStringContainsString('alt="Diagram"', $html);
        self::assertStringContainsString('hm-slide--split', $html);
        self::assertStringContainsString('data-slide-type="split"', $html);
        self::assertStringContainsString('hm-slide--split--text-left', $html);
    }

    public function testBuildsSplitSlideWithTextRightLayout(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_SPLIT, [
            'title' => 'Text Right',
            'layout' => 'text-right',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('hm-slide--split--text-right', $html);
        self::assertStringNotContainsString('hm-slide--split--text-left', $html);
    }

    public function testSplitSlideDefaultsToTextLeftForInvalidLayout(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_SPLIT, [
            'title' => 'Invalid Layout',
            'layout' => 'bad-value',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('hm-slide--split--text-left', $html);
    }

    public function testSplitSlideRendersPlaceholderWhenImageUrlAbsent(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_SPLIT, [
            'title' => 'No Image',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('hm-slide__image-placeholder', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testSplitSlideRejectsJavascriptProtocolImageUrl(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_SPLIT, [
            'title' => 'XSS attempt',
            'image_url' => 'javascript:alert(1)',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testSplitSlideEscapesHtml(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_SPLIT, [
            'title' => '<script>bad()</script>',
            'body' => '<b>bold</b>',
            'items' => ['<em>item</em>'],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>bold', $html);
        self::assertStringNotContainsString('<em>item', $html);
    }

    public function testSplitSlideFiltersEmptyItems(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_SPLIT, [
            'title' => 'Filtered',
            'items' => ['First', '', '  ', 'Last'],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('First', $html);
        self::assertStringContainsString('Last', $html);
        self::assertSame(2, substr_count($html, '<li '));
    }

    public function testSplitSlideUsesHmCssTokens(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_SPLIT, ['title' => 'T']);
        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('--hm-', $html);
    }

    // ── image slide ───────────────────────────────────────────────────────────

    public function testBuildsImageSlideWithAllFields(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_IMAGE, [
            'image_url' => 'https://example.com/hero.jpg',
            'image_alt' => 'Hero image',
            'overlay_text' => 'Bold Headline',
            'caption' => 'Photo credit: Harmony',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('src="https://example.com/hero.jpg"', $html);
        self::assertStringContainsString('alt="Hero image"', $html);
        self::assertStringContainsString('Bold Headline', $html);
        self::assertStringContainsString('Photo credit: Harmony', $html);
        self::assertStringContainsString('hm-slide--image', $html);
        self::assertStringContainsString('data-slide-type="image"', $html);
    }

    public function testImageSlideRendersPlaceholderWhenImageUrlAbsent(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_IMAGE, []);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('hm-slide__image-placeholder', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testImageSlideHidesOverlayWhenEmpty(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_IMAGE, [
            'image_url' => 'https://example.com/img.jpg',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('class="hm-slide__overlay"', $html);
    }

    public function testImageSlideHidesCaptionWhenEmpty(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_IMAGE, [
            'image_url' => 'https://example.com/img.jpg',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<figcaption', $html);
    }

    public function testImageSlideRejectsJavascriptProtocolUrl(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_IMAGE, [
            'image_url' => 'javascript:alert(1)',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testImageSlideEscapesHtml(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_IMAGE, [
            'image_url' => 'https://example.com/img.jpg',
            'overlay_text' => '<script>evil()</script>',
            'caption' => '<b>caption</b>',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>caption', $html);
    }

    public function testImageSlideUsesHmCssTokens(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_IMAGE, []);
        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('--hm-', $html);
    }

    // ── quote slide ───────────────────────────────────────────────────────────

    public function testBuildsQuoteSlideWithAllFields(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_QUOTE, [
            'quote' => 'Design is how it works.',
            'author' => 'Steve Jobs',
            'role' => 'Co-founder, Apple',
            'source' => 'NYT, 2003',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Design is how it works.', $html);
        self::assertStringContainsString('Steve Jobs', $html);
        self::assertStringContainsString('Co-founder, Apple', $html);
        self::assertStringContainsString('NYT, 2003', $html);
        self::assertStringContainsString('hm-slide--quote', $html);
        self::assertStringContainsString('data-slide-type="quote"', $html);
    }

    public function testBuildsQuoteSlideWithQuoteOnly(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_QUOTE, [
            'quote' => 'Simplicity is the ultimate sophistication.',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Simplicity is the ultimate sophistication.', $html);
        self::assertStringNotContainsString('<footer', $html);
    }

    public function testQuoteSlideHidesRoleWhenEmpty(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_QUOTE, [
            'quote' => 'A great quote.',
            'author' => 'Anonymous',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Anonymous', $html);
        self::assertStringNotContainsString('class="hm-slide__quote-role"', $html);
        self::assertStringNotContainsString('class="hm-slide__quote-source"', $html);
    }

    public function testQuoteSlideEscapesHtml(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_QUOTE, [
            'quote' => '<script>alert(1)</script>',
            'author' => '<b>Author</b>',
            'role' => '<em>role</em>',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>Author', $html);
        self::assertStringNotContainsString('<em>role', $html);
    }

    public function testQuoteSlideUsesHmCssTokens(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_QUOTE, ['quote' => 'Q']);
        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('--hm-', $html);
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
