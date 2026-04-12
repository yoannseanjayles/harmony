<?php

namespace App\Tests\Support\Slide;

/**
 * Fixtures of valid contentJson arrays for slide types title, content and closing (Phase 1).
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
}
