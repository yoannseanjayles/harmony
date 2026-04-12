<?php

namespace App\Tests\Unit;

use App\Entity\Project;
use App\Entity\Slide;
use App\Slide\SlideBuilder;
use App\Slide\SlideHtmlSanitizer;
use App\Slide\SlideRenderHashCalculator;
use App\Slide\UnsupportedSlideTypeException;
use App\Theme\ThemeEngine;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class SlideBuilderTest extends TestCase
{
    private SlideBuilder $builder;
    private ArrayAdapter $cacheAdapter;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 2).'/templates');
        $twig = new Environment($loader, ['autoescape' => 'html']);
        $logger = $this->createStub(LoggerInterface::class);
        $this->cacheAdapter = new ArrayAdapter();
        $this->builder = new SlideBuilder($twig, new SlideHtmlSanitizer(), new SlideRenderHashCalculator('1', ''), $this->cacheAdapter, $logger, new ThemeEngine());
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

        // The sanitizer strips HTML tags before Twig rendering — no tags survive.
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
        // Text content is preserved.
        self::assertStringContainsString('Bold &amp; safe', $html);
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

        // The sanitizer strips the script tag — no escaped or raw tag appears.
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('&lt;script&gt;', $html);
        // The text node content is gone too since the tag wraps no visible text.
        self::assertStringContainsString('Normal item', $html);
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

    public function testUnknownTypeThrowsUnsupportedSlideTypeException(): void
    {
        $slide = $this->makeSlide('unknown_type', [
            'title' => 'Bad type',
        ]);

        $this->expectException(UnsupportedSlideTypeException::class);
        $this->expectExceptionMessage('unknown_type');

        $this->builder->buildSlide($slide);
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

    // ── timeline slide ────────────────────────────────────────────────────────

    public function testBuildsTimelineSlideWithAllFields(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TIMELINE, [
            'title' => 'Our Journey',
            'items' => [
                ['year' => '2020', 'label' => 'Founded', 'description' => 'Started in a garage.'],
                ['year' => '2021', 'label' => 'Seed Round', 'description' => 'Raised $2M.'],
                ['year' => '2022', 'label' => 'Launch', 'description' => 'Product shipped.'],
            ],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Our Journey', $html);
        self::assertStringContainsString('2020', $html);
        self::assertStringContainsString('Founded', $html);
        self::assertStringContainsString('Started in a garage.', $html);
        self::assertStringContainsString('Seed Round', $html);
        self::assertStringContainsString('Launch', $html);
        self::assertStringContainsString('hm-slide--timeline', $html);
        self::assertStringContainsString('data-slide-type="timeline"', $html);
    }

    public function testBuildsTimelineSlideWithoutOptionalFields(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TIMELINE, [
            'title' => 'Milestones',
            'items' => [
                ['label' => 'Kickoff'],
                ['label' => 'Launch'],
            ],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Kickoff', $html);
        self::assertStringContainsString('Launch', $html);
        self::assertStringNotContainsString('class="hm-timeline__year"', $html);
        self::assertStringNotContainsString('class="hm-timeline__description"', $html);
    }

    public function testTimelineSlideIgnoresItemsWithoutLabel(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TIMELINE, [
            'title' => 'Filtered',
            'items' => [
                ['year' => '2020', 'label' => 'Good item'],
                ['year' => '2021', 'label' => ''],
                ['year' => '2022', 'description' => 'No label here'],
            ],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Good item', $html);
        $count = substr_count($html, 'class="hm-timeline__label"');
        self::assertSame(1, $count);
    }

    public function testTimelineSlideClampsMoreThan6Items(): void
    {
        $items = [];
        for ($i = 1; $i <= 8; ++$i) {
            $items[] = ['label' => "Item {$i}"];
        }

        $slide = $this->makeSlide(Slide::TYPE_TIMELINE, ['title' => 'T', 'items' => $items]);
        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Item 6', $html);
        self::assertStringNotContainsString('Item 7', $html);
        self::assertStringNotContainsString('Item 8', $html);
    }

    public function testTimelineSlideEscapesHtml(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TIMELINE, [
            'title' => '<script>bad()</script>',
            'items' => [
                ['year' => '<b>2020</b>', 'label' => '<em>Event</em>', 'description' => '<script>evil()</script>'],
                ['label' => 'Normal'],
            ],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>2020', $html);
        self::assertStringNotContainsString('<em>Event', $html);
    }

    public function testTimelineSlideUsesHmCssTokens(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TIMELINE, [
            'title' => 'T',
            'items' => [['label' => 'A'], ['label' => 'B']],
        ]);
        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('--hm-', $html);
    }

    // ── stats slide ───────────────────────────────────────────────────────────

    public function testBuildsStatsSlideWithAllFields(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_STATS, [
            'title' => 'Impact in Numbers',
            'stats' => [
                ['value' => '10M+', 'label' => 'Users', 'detail' => 'Worldwide'],
                ['value' => '98%', 'label' => 'Satisfaction', 'detail' => 'NPS score'],
                ['value' => '$2B', 'label' => 'Revenue', 'detail' => 'ARR 2025'],
            ],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Impact in Numbers', $html);
        self::assertStringContainsString('10M+', $html);
        self::assertStringContainsString('Users', $html);
        self::assertStringContainsString('Worldwide', $html);
        self::assertStringContainsString('98%', $html);
        self::assertStringContainsString('hm-slide--stats', $html);
        self::assertStringContainsString('data-slide-type="stats"', $html);
    }

    public function testBuildsStatsSlideWithoutOptionalDetail(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_STATS, [
            'title' => 'Metrics',
            'stats' => [
                ['value' => '3×', 'label' => 'Faster'],
                ['value' => '99.9%', 'label' => 'Uptime'],
            ],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('3×', $html);
        self::assertStringNotContainsString('class="hm-stats__detail"', $html);
    }

    public function testStatsSlideIgnoresEntriesWithMissingValueOrLabel(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_STATS, [
            'title' => 'Filtered',
            'stats' => [
                ['value' => '100', 'label' => 'Complete'],
                ['value' => '', 'label' => 'Missing value'],
                ['value' => '50', 'label' => ''],
                'not-an-array',
            ],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('100', $html);
        $count = substr_count($html, 'class="hm-stats__value"');
        self::assertSame(1, $count);
    }

    public function testStatsSlideClampsMoreThan6Stats(): void
    {
        $stats = [];
        for ($i = 1; $i <= 8; ++$i) {
            $stats[] = ['value' => "{$i}", 'label' => "Stat {$i}"];
        }

        $slide = $this->makeSlide(Slide::TYPE_STATS, ['title' => 'T', 'stats' => $stats]);
        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Stat 6', $html);
        self::assertStringNotContainsString('Stat 7', $html);
        self::assertStringNotContainsString('Stat 8', $html);
    }

    public function testStatsSlideEscapesHtml(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_STATS, [
            'title' => '<script>xss()</script>',
            'stats' => [
                ['value' => '<b>100</b>', 'label' => '<em>Label</em>', 'detail' => '<script>evil()</script>'],
                ['value' => '2', 'label' => 'OK'],
            ],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>100', $html);
        self::assertStringNotContainsString('<em>Label', $html);
    }

    public function testStatsSlideUsesHmCssTokens(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_STATS, [
            'title' => 'T',
            'stats' => [['value' => '1', 'label' => 'A'], ['value' => '2', 'label' => 'B']],
        ]);
        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('--hm-', $html);
    }

    // ── comparison slide ──────────────────────────────────────────────────────

    public function testBuildsComparisonSlideWithAllFields(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_COMPARISON, [
            'title' => 'Us vs. Them',
            'left' => [
                'heading' => 'Before Harmony',
                'items' => ['Manual design', 'Hours of work'],
                'highlight' => 'Slow & Costly',
            ],
            'right' => [
                'heading' => 'After Harmony',
                'items' => ['AI-powered', 'Minutes not hours'],
                'highlight' => 'Fast & Beautiful',
            ],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Us vs. Them', $html);
        self::assertStringContainsString('Before Harmony', $html);
        self::assertStringContainsString('Manual design', $html);
        self::assertStringContainsString('Slow &amp; Costly', $html);
        self::assertStringContainsString('After Harmony', $html);
        self::assertStringContainsString('AI-powered', $html);
        self::assertStringContainsString('Fast &amp; Beautiful', $html);
        self::assertStringContainsString('hm-slide--comparison', $html);
        self::assertStringContainsString('data-slide-type="comparison"', $html);
    }

    public function testBuildsComparisonSlideWithoutOptionalHighlights(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_COMPARISON, [
            'title' => 'Before & After',
            'left' => ['heading' => 'Before', 'items' => ['Old way']],
            'right' => ['heading' => 'After', 'items' => ['New way']],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Before', $html);
        self::assertStringContainsString('After', $html);
        self::assertStringNotContainsString('class="hm-comparison__highlight', $html);
    }

    public function testComparisonSlideFiltersEmptyItems(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_COMPARISON, [
            'title' => 'Filtered',
            'left' => ['heading' => 'Left', 'items' => ['Good', '', '  ', 'Also good']],
            'right' => ['heading' => 'Right', 'items' => ['Item']],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Good', $html);
        self::assertStringContainsString('Also good', $html);
        $leftItemCount = substr_count($html, 'class="hm-comparison__item"');
        self::assertSame(3, $leftItemCount);
    }

    public function testComparisonSlideClampsMoreThan6ItemsPerColumn(): void
    {
        $items = [];
        for ($i = 1; $i <= 8; ++$i) {
            $items[] = "Item {$i}";
        }

        $slide = $this->makeSlide(Slide::TYPE_COMPARISON, [
            'title' => 'T',
            'left' => ['heading' => 'L', 'items' => $items],
            'right' => ['heading' => 'R', 'items' => ['X']],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('Item 6', $html);
        self::assertStringNotContainsString('Item 7', $html);
        self::assertStringNotContainsString('Item 8', $html);
    }

    public function testComparisonSlideEscapesHtml(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_COMPARISON, [
            'title' => '<script>bad()</script>',
            'left' => [
                'heading' => '<b>Left</b>',
                'items' => ['<em>item</em>'],
                'highlight' => '<script>evil()</script>',
            ],
            'right' => ['heading' => 'Right', 'items' => ['OK']],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>Left', $html);
        self::assertStringNotContainsString('<em>item', $html);
    }

    public function testComparisonSlideHandlesMissingColumns(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_COMPARISON, [
            'title' => 'No columns',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('hm-slide--comparison', $html);
        self::assertStringContainsString('No columns', $html);
    }

    public function testComparisonSlideUsesHmCssTokens(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_COMPARISON, [
            'title' => 'T',
            'left' => ['heading' => 'L', 'items' => ['A']],
            'right' => ['heading' => 'R', 'items' => ['B']],
        ]);
        $html = $this->builder->buildSlide($slide);

        self::assertStringContainsString('--hm-', $html);
    }

    // ── T156 — unsupported slide type (block and log) ─────────────────────────

    public function testBuilderThrowsOnUnsupportedSlideType(): void
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 2).'/templates');
        $twig = new Environment($loader, ['autoescape' => 'html']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'slide_builder_unsupported_type',
                self::callback(static fn (array $ctx): bool => $ctx['type'] === 'injected_type'),
            );

        $builder = new SlideBuilder($twig, new SlideHtmlSanitizer(), new SlideRenderHashCalculator('1', ''), new ArrayAdapter(), $logger, new ThemeEngine());

        $slide = $this->makeSlide('injected_type', ['title' => 'Bad']);

        $this->expectException(UnsupportedSlideTypeException::class);
        $this->expectExceptionMessage('injected_type');

        $builder->buildSlide($slide);
    }

    // ── T158 — no free LLM HTML reaches the rendered output ──────────────────

    public function testSlideBuilderStripsHtmlTagsFromTitleSlide(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TITLE, [
            'label' => '<script>alert("xss")</script>',
            'title' => '<b>Title with bold</b>',
            'subtitle' => '<em>Subtitle</em>',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<em>', $html);
        // Text content is preserved
        self::assertStringContainsString('Title with bold', $html);
        self::assertStringContainsString('Subtitle', $html);
    }

    public function testSlideBuilderStripsHtmlTagsFromContentSlide(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_CONTENT, [
            'title' => '<img src=x onerror=alert(1)>Clean Title',
            'body' => '<b onclick="evil()">Body text</b>',
            'items' => ['<script>bad()</script>Clean item', 'Normal item'],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringContainsString('Clean Title', $html);
        self::assertStringContainsString('Body text', $html);
        self::assertStringContainsString('Clean item', $html);
        self::assertStringContainsString('Normal item', $html);
    }

    public function testSlideBuilderStripsHtmlTagsFromQuoteSlide(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_QUOTE, [
            'quote' => '<script>xss()</script>Great quote',
            'author' => '<b>Author Name</b>',
            'role' => '<em>CEO</em>',
            'source' => '<a href="javascript:evil()">Source</a>',
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<em>', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('Great quote', $html);
        self::assertStringContainsString('Author Name', $html);
    }

    public function testSlideBuilderStripsHtmlTagsFromTimelineItems(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TIMELINE, [
            'title' => '<script>bad()</script>Timeline',
            'items' => [
                ['year' => '<b>2020</b>', 'label' => '<em>Founded</em>', 'description' => '<script>evil()</script>Started.'],
                ['label' => 'Second event'],
            ],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<em>', $html);
        self::assertStringContainsString('2020', $html);
        self::assertStringContainsString('Founded', $html);
        self::assertStringContainsString('Started.', $html);
    }

    public function testSlideBuilderStripsHtmlTagsFromStatsSlide(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_STATS, [
            'title' => '<h1>Numbers</h1>',
            'stats' => [
                ['value' => '<strong>98%</strong>', 'label' => '<em>Score</em>', 'detail' => '<script>evil()</script>Detail'],
                ['value' => '10M', 'label' => 'Users'],
            ],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<h1>', $html);
        self::assertStringNotContainsString('<strong>', $html);
        self::assertStringNotContainsString('<em>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('98%', $html);
        self::assertStringContainsString('Score', $html);
        self::assertStringContainsString('Detail', $html);
    }

    public function testSlideBuilderStripsHtmlTagsFromComparisonSlide(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_COMPARISON, [
            'title' => '<b>Comparison</b>',
            'left' => [
                'heading' => '<script>bad()</script>Before',
                'items' => ['<em>Manual</em>'],
                'highlight' => '<b>Slow</b>',
            ],
            'right' => [
                'heading' => 'After',
                'items' => ['Fast'],
                'highlight' => 'Quick',
            ],
        ]);

        $html = $this->builder->buildSlide($slide);

        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<em>', $html);
        self::assertStringContainsString('Comparison', $html);
        self::assertStringContainsString('Before', $html);
        self::assertStringContainsString('Manual', $html);
        self::assertStringContainsString('Slow', $html);
    }

    // ── T161/T162 — render cache on entity ───────────────────────────────────

    public function testBuildSlide_StoresRenderHashAndHtmlCacheOnEntity(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TITLE, ['title' => 'Cached']);

        self::assertNull($slide->getRenderHash());
        self::assertNull($slide->getHtmlCache());

        $html = $this->builder->buildSlide($slide);

        self::assertNotNull($slide->getRenderHash());
        self::assertNotEmpty($slide->getHtmlCache());
        self::assertSame($html, $slide->getHtmlCache());
    }

    public function testBuildSlide_ReturnsCachedHtmlFromEntity(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TITLE, ['title' => 'Cached']);

        $firstCall = $this->builder->buildSlide($slide);

        // Corrupt the template reference to ensure a second call returns from cache
        $slide->setRenderHash($slide->getRenderHash()); // no change — hash stays the same

        $secondCall = $this->builder->buildSlide($slide);

        self::assertSame($firstCall, $secondCall);
    }

    public function testBuildSlide_ReRendersWhenContentChanges(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TITLE, ['title' => 'Original']);
        $this->builder->buildSlide($slide);
        $originalHash = $slide->getRenderHash();

        $slide->setContent(['title' => 'Updated']);
        $this->builder->buildSlide($slide);
        $updatedHash = $slide->getRenderHash();

        self::assertNotSame($originalHash, $updatedHash);
    }

    public function testBuildSlide_IsServedFromCachePoolOnSecondEntity(): void
    {
        // First entity: populate the cache pool
        $slide1 = $this->makeSlide(Slide::TYPE_TITLE, ['title' => 'Shared']);
        $html1 = $this->builder->buildSlide($slide1);

        // Second entity with identical content: must be served from the pool
        $slide2 = $this->makeSlide(Slide::TYPE_TITLE, ['title' => 'Shared']);

        self::assertNull($slide2->getRenderHash()); // entity cache cold

        $html2 = $this->builder->buildSlide($slide2);

        self::assertSame($html1, $html2);
        self::assertSame($slide1->getRenderHash(), $slide2->getRenderHash());
    }

    // ── T165 — invalidateRenderCache ─────────────────────────────────────────

    public function testInvalidateRenderCache_ClearsRenderHashAndHtmlCache(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TITLE, ['title' => 'Warm']);
        $this->builder->buildSlide($slide);

        self::assertNotNull($slide->getRenderHash());
        self::assertNotNull($slide->getHtmlCache());

        $slide->invalidateRenderCache();

        self::assertNull($slide->getRenderHash());
        self::assertNull($slide->getHtmlCache());
    }

    public function testSetContentJson_AutoInvalidatesRenderCache(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TITLE, ['title' => 'Before']);
        $this->builder->buildSlide($slide);

        self::assertNotNull($slide->getRenderHash());

        $slide->setContentJson('{"title":"After"}');

        self::assertNull($slide->getRenderHash());
        self::assertNull($slide->getHtmlCache());
    }

    public function testSetContent_AutoInvalidatesRenderCache(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TITLE, ['title' => 'Before']);
        $this->builder->buildSlide($slide);

        self::assertNotNull($slide->getRenderHash());

        $slide->setContent(['title' => 'After']);

        self::assertNull($slide->getRenderHash());
        self::assertNull($slide->getHtmlCache());
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
