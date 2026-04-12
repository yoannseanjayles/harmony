<?php

namespace App\Tests\Support\Slide;

/**
 * Fixtures of valid contentJson arrays for slide types title, content and closing (Phase 1),
 * and split, image and quote (Phase 2).
 *
 * Usage:
 *   $content = SlideContentFixtures::titleFull();
 *   $slide->setContent($content);
 */
final class SlideContentFixtures
{
    // ── title ──────────────────────────────────────────────────────────────

    /**
     * Title slide with all optional fields populated.
     *
     * @return array<string, mixed>
     */
    public static function titleFull(): array
    {
        return [
            'label' => 'Keynote 2025',
            'title' => 'Harmony — AI-Powered Presentations',
            'subtitle' => 'Create stunning decks in minutes, not hours.',
        ];
    }

    /**
     * Title slide with only the required title field.
     *
     * @return array<string, mixed>
     */
    public static function titleMinimal(): array
    {
        return [
            'title' => 'Welcome',
        ];
    }

    /**
     * Title slide with label and title but no subtitle.
     *
     * @return array<string, mixed>
     */
    public static function titleWithLabel(): array
    {
        return [
            'label' => 'Section 1',
            'title' => 'The Problem We Solve',
        ];
    }

    // ── content ────────────────────────────────────────────────────────────

    /**
     * Content slide with title, body paragraph and bullet list.
     *
     * @return array<string, mixed>
     */
    public static function contentFull(): array
    {
        return [
            'title' => 'Key Benefits',
            'body' => 'Our platform delivers measurable results from day one.',
            'items' => [
                '10× faster deck creation',
                'Real-time AI collaboration',
                'One-click HTML & PDF export',
                'Full theme customisation',
            ],
        ];
    }

    /**
     * Content slide with title and body only (no bullet list).
     *
     * @return array<string, mixed>
     */
    public static function contentWithBodyOnly(): array
    {
        return [
            'title' => 'Our Mission',
            'body' => 'We believe every idea deserves a beautiful presentation.',
        ];
    }

    /**
     * Content slide with title and items only (no body paragraph).
     *
     * @return array<string, mixed>
     */
    public static function contentWithItemsOnly(): array
    {
        return [
            'title' => 'Roadmap Highlights',
            'items' => [
                'Q1 — Beta launch',
                'Q2 — Team workspaces',
                'Q3 — Enterprise SSO',
            ],
        ];
    }

    /**
     * Content slide with only the required title field.
     *
     * @return array<string, mixed>
     */
    public static function contentMinimal(): array
    {
        return [
            'title' => 'Minimal Slide',
        ];
    }

    // ── closing ────────────────────────────────────────────────────────────

    /**
     * Closing slide with message and a call-to-action link.
     *
     * @return array<string, mixed>
     */
    public static function closingFull(): array
    {
        return [
            'message' => 'Thank you. Let's build something great together.',
            'cta_label' => 'Start free trial',
            'cta_url' => 'https://harmony.app/signup',
        ];
    }

    /**
     * Closing slide with a CTA label but no URL (rendered as plain text).
     *
     * @return array<string, mixed>
     */
    public static function closingWithCtaLabelOnly(): array
    {
        return [
            'message' => 'Questions? We're here to help.',
            'cta_label' => 'Contact us',
        ];
    }

    /**
     * Closing slide with only the required message field.
     *
     * @return array<string, mixed>
     */
    public static function closingMinimal(): array
    {
        return [
            'message' => 'Thank you for your attention.',
        ];
    }

    // ── split ──────────────────────────────────────────────────────────────

    /**
     * Split slide with title, body, items, image and default (text-left) layout.
     *
     * @return array<string, mixed>
     */
    public static function splitFull(): array
    {
        return [
            'title' => 'How It Works',
            'body' => 'Our three-step process makes presentations effortless.',
            'items' => [
                'Describe your idea in plain language',
                'AI generates structured slides instantly',
                'Refine with one-click edits',
            ],
            'image_url' => 'https://harmony.app/assets/how-it-works.jpg',
            'image_alt' => 'Diagram showing the three-step process',
            'layout' => 'text-left',
        ];
    }

    /**
     * Split slide with image on the left (text-right layout).
     *
     * @return array<string, mixed>
     */
    public static function splitTextRight(): array
    {
        return [
            'title' => 'Visual Impact',
            'body' => 'High-resolution imagery paired with concise copy.',
            'image_url' => 'https://harmony.app/assets/visual.jpg',
            'image_alt' => 'Abstract visual',
            'layout' => 'text-right',
        ];
    }

    /**
     * Split slide with title only and no image (graceful degradation).
     *
     * @return array<string, mixed>
     */
    public static function splitMinimal(): array
    {
        return [
            'title' => 'Minimal Split',
        ];
    }

    // ── image ──────────────────────────────────────────────────────────────

    /**
     * Image slide with all optional fields populated.
     *
     * @return array<string, mixed>
     */
    public static function imageFull(): array
    {
        return [
            'image_url' => 'https://harmony.app/assets/hero.jpg',
            'image_alt' => 'Team collaborating in a modern office',
            'overlay_text' => 'The Future of Presentations',
            'caption' => 'Harmony platform — April 2025',
        ];
    }

    /**
     * Image slide with asset and overlay but no caption.
     *
     * @return array<string, mixed>
     */
    public static function imageWithOverlayOnly(): array
    {
        return [
            'image_url' => 'https://harmony.app/assets/city.jpg',
            'image_alt' => 'City skyline at dusk',
            'overlay_text' => 'Our Global Reach',
        ];
    }

    /**
     * Image slide with no asset (graceful degradation).
     *
     * @return array<string, mixed>
     */
    public static function imageMinimal(): array
    {
        return [];
    }

    // ── quote ──────────────────────────────────────────────────────────────

    /**
     * Quote slide with all optional fields populated.
     *
     * @return array<string, mixed>
     */
    public static function quoteFull(): array
    {
        return [
            'quote' => 'Design is not just what it looks like and feels like. Design is how it works.',
            'author' => 'Steve Jobs',
            'role' => 'Co-founder, Apple Inc.',
            'source' => 'The New York Times Magazine, 2003',
        ];
    }

    /**
     * Quote slide with author but no role or source.
     *
     * @return array<string, mixed>
     */
    public static function quoteWithAuthorOnly(): array
    {
        return [
            'quote' => 'Simplicity is the ultimate sophistication.',
            'author' => 'Leonardo da Vinci',
        ];
    }

    /**
     * Quote slide with only the required quote field.
     *
     * @return array<string, mixed>
     */
    public static function quoteMinimal(): array
    {
        return [
            'quote' => 'The best way to predict the future is to invent it.',
        ];
    }
}
