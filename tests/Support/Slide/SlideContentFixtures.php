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

    // ── timeline ───────────────────────────────────────────────────────────

    /**
     * Timeline slide with all optional fields populated (4 items).
     *
     * @return array<string, mixed>
     */
    public static function timelineFull(): array
    {
        return [
            'title' => 'Our Journey',
            'items' => [
                ['year' => '2020', 'label' => 'Founded', 'description' => 'Started with a vision to transform presentations.'],
                ['year' => '2021', 'label' => 'Seed Round', 'description' => 'Raised $2M to build the core product.'],
                ['year' => '2022', 'label' => 'Public Beta', 'description' => 'Launched to 5,000 early adopters worldwide.'],
                ['year' => '2023', 'label' => 'Series A', 'description' => 'Raised $15M and expanded to 50+ markets.'],
            ],
        ];
    }

    /**
     * Timeline slide with the minimum 2 items (no descriptions).
     *
     * @return array<string, mixed>
     */
    public static function timelineMinimal(): array
    {
        return [
            'title' => 'Milestones',
            'items' => [
                ['year' => 'Q1', 'label' => 'Kickoff'],
                ['year' => 'Q4', 'label' => 'Launch'],
            ],
        ];
    }

    /**
     * Timeline slide with 6 items (maximum allowed).
     *
     * @return array<string, mixed>
     */
    public static function timelineMaxItems(): array
    {
        return [
            'title' => 'Product Roadmap',
            'items' => [
                ['label' => 'Discovery phase', 'description' => 'Research and user interviews.'],
                ['label' => 'MVP design', 'description' => 'Wireframes and prototypes.'],
                ['label' => 'Engineering sprint', 'description' => 'Core feature development.'],
                ['label' => 'QA & testing', 'description' => 'Automated and manual testing.'],
                ['label' => 'Soft launch', 'description' => 'Beta to 500 selected users.'],
                ['label' => 'General availability', 'description' => 'Open to all users.'],
            ],
        ];
    }

    // ── stats ──────────────────────────────────────────────────────────────

    /**
     * Stats slide with 4 key figures and all optional fields.
     *
     * @return array<string, mixed>
     */
    public static function statsFull(): array
    {
        return [
            'title' => 'Impact in Numbers',
            'stats' => [
                ['value' => '10M+', 'label' => 'Users', 'detail' => 'Worldwide active users'],
                ['value' => '98%', 'label' => 'Satisfaction', 'detail' => 'NPS score, 2025'],
                ['value' => '$2B', 'label' => 'Revenue', 'detail' => 'ARR projected 2025'],
                ['value' => '150+', 'label' => 'Countries', 'detail' => 'Global reach'],
            ],
        ];
    }

    /**
     * Stats slide with the minimum 2 stats (no detail).
     *
     * @return array<string, mixed>
     */
    public static function statsMinimal(): array
    {
        return [
            'title' => 'Key Metrics',
            'stats' => [
                ['value' => '3×', 'label' => 'Faster'],
                ['value' => '99.9%', 'label' => 'Uptime'],
            ],
        ];
    }

    /**
     * Stats slide with 6 items (maximum allowed).
     *
     * @return array<string, mixed>
     */
    public static function statsMaxItems(): array
    {
        return [
            'title' => 'Platform at a Glance',
            'stats' => [
                ['value' => '500K+', 'label' => 'Decks created', 'detail' => 'Since launch'],
                ['value' => '4.9★', 'label' => 'App rating', 'detail' => 'App Store & Play Store'],
                ['value' => '<2s', 'label' => 'Generation time', 'detail' => 'Per slide, p95'],
                ['value' => '40+', 'label' => 'Templates', 'detail' => 'Ready-to-use designs'],
                ['value' => '3', 'label' => 'AI providers', 'detail' => 'Claude, OpenAI, Mistral'],
                ['value' => 'SOC 2', 'label' => 'Certified', 'detail' => 'Type II compliance'],
            ],
        ];
    }

    // ── comparison ─────────────────────────────────────────────────────────

    /**
     * Comparison slide with both columns fully populated.
     *
     * @return array<string, mixed>
     */
    public static function comparisonFull(): array
    {
        return [
            'title' => 'Harmony vs. Traditional Tools',
            'left' => [
                'heading' => 'Traditional Workflow',
                'items' => [
                    'Hours of manual design',
                    'No AI assistance',
                    'Static, hard to update',
                    'Export only as PPTX',
                ],
                'highlight' => 'Slow & Costly',
            ],
            'right' => [
                'heading' => 'With Harmony',
                'items' => [
                    'AI generates slides in minutes',
                    'Conversational editing',
                    'Live preview & instant updates',
                    'HTML + PDF export in one click',
                ],
                'highlight' => 'Fast & Beautiful',
            ],
        ];
    }

    /**
     * Comparison slide with minimal content (no highlights, single item per column).
     *
     * @return array<string, mixed>
     */
    public static function comparisonMinimal(): array
    {
        return [
            'title' => 'Before & After',
            'left' => [
                'heading' => 'Before',
                'items' => ['Manual process'],
            ],
            'right' => [
                'heading' => 'After',
                'items' => ['Automated'],
            ],
        ];
    }

    /**
     * Comparison slide with 6 items per column (maximum allowed).
     *
     * @return array<string, mixed>
     */
    public static function comparisonMaxItems(): array
    {
        return [
            'title' => 'Feature Comparison',
            'left' => [
                'heading' => 'Competitor',
                'items' => [
                    'Fixed templates only',
                    'No AI generation',
                    'Manual theme setup',
                    'No real-time preview',
                    'Basic export options',
                    'No version history',
                ],
                'highlight' => 'Limited',
            ],
            'right' => [
                'heading' => 'Harmony',
                'items' => [
                    'Unlimited custom layouts',
                    'Conversational AI engine',
                    'One-click theme presets',
                    'Live slide preview',
                    'HTML & PDF export',
                    'Full version history',
                ],
                'highlight' => 'Complete',
            ],
        ];
    }
}
