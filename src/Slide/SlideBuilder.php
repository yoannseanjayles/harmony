<?php

namespace App\Slide;

use App\Entity\Slide;
use App\Media\MediaUrlResolver;
use App\Theme\ThemeEngine;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Environment;

final class SlideBuilder
{
    public function __construct(
        private readonly Environment $twig,
        private readonly SlideHtmlSanitizer $sanitizer,
        private readonly SlideRenderHashCalculator $hashCalculator,
        #[Autowire(service: 'harmony.slides')]
        private readonly CacheInterface $slideCache,
        #[Autowire(service: 'monolog.logger.ai')]
        private readonly LoggerInterface $logger,
        private readonly ThemeEngine $themeEngine,
        private readonly MediaUrlResolver $mediaUrlResolver,
    ) {
    }

    /**
     * T161/T162 — Render a slide to HTML, using the deterministic render cache.
     *
     * Lookup order:
     *   1. Entity cache: if renderHash on the entity matches the computed hash, return htmlCache.
     *   2. Symfony cache pool: if the hash key is present in the pool, return the cached HTML
     *      and back-fill the entity fields.
     *   3. Full render via Twig: store the result in both the pool and the entity fields.
     *
     * The entity's renderHash and htmlCache fields are always updated after a pool or full
     * render so that subsequent calls within the same request are served from the entity.
     */
    public function buildSlide(Slide $slide): string
    {
        $project   = $slide->getProject();
        // T202 — use the effective theme (preset base + user overrides merged)
        $themeJson = $project?->getEffectiveThemeConfigJson() ?? '{}';
        // T204 — include themeVersion so theme-only changes bust the render cache
        $themeVersion = (string) ($project?->getThemeVersion() ?? 1);
        $hash = $this->hashCalculator->compute($slide->getContentJson(), $themeJson, $themeVersion);

        // T237 — Slides with media references contain signed URLs that expire; skip both
        // the entity cache and the Symfony pool so every render uses fresh signed URLs.
        $hasMediaRefs = $slide->getMediaRefs() !== [];

        // 1. Entity cache hit (T162) — cheapest check, no I/O
        if (!$hasMediaRefs && $slide->getRenderHash() === $hash && $slide->getHtmlCache() !== null) {
            $this->logger->debug('slide_cache_hit', [
                'hash' => substr($hash, 0, 8),
                'source' => 'entity',
                'slide_id' => $slide->getId(),
            ]);

            return $slide->getHtmlCache();
        }

        // Validate the slide type early so that an UnsupportedSlideTypeException propagates
        // before any cache I/O occurs.
        $template = $this->resolveTemplate($slide->getType());

        // 2. Symfony cache pool (T163/T164) — fast I/O-backed check
        // T237 — bypass for slides with media refs (signed URLs must stay fresh)
        $renderStart = microtime(true);
        $cacheHit = true;

        if ($hasMediaRefs) {
            // Always do a full render so signed URLs are freshly generated.
            $cacheHit = false;
            $context  = $this->buildContext($slide);
            $html     = $this->twig->render($template, $context);
        } else {
            $html = $this->slideCache->get(
                'slide_' . $hash,
                function (ItemInterface $item) use ($slide, $template, &$cacheHit): string {
                    $cacheHit = false;
                    $context = $this->buildContext($slide);

                    return $this->twig->render($template, $context);
                },
            );
        }

        // T168 — log render duration and cache outcome for performance monitoring
        $durationMs = (int) round((microtime(true) - $renderStart) * 1000);
        $this->logger->debug($cacheHit ? 'slide_cache_hit' : 'slide_cache_miss', [
            'hash' => substr($hash, 0, 8),
            'source' => $cacheHit ? 'pool' : 'render',
            'duration_ms' => $durationMs,
            'slide_id' => $slide->getId(),
        ]);

        // T181 — prepend the theme CSS override block so custom tokens are applied in the
        // slide's isolated HTML snippet (entity cache stores the full, theme-aware HTML).
        $themeStyle = $this->themeEngine->toCssBlock($themeJson);
        if ($themeStyle !== '') {
            $html = $themeStyle . "\n" . $html;
        }

        // T161 — back-fill entity fields so the entity check is a hit on the next call
        // T237 — do not cache slides with media refs (signed URLs expire)
        if (!$hasMediaRefs) {
            $slide->setRenderHash($hash);
            $slide->setHtmlCache($html);
        }

        return $html;
    }

    /**
     * Resolve the Twig template path for a given slide type.
     *
     * T155/T156: If the type is not in the supported list the attempt is logged and an
     * exception is thrown — the render is blocked so that no unrecognised template path
     * (or a silent fallback) reaches the output.
     *
     * @throws UnsupportedSlideTypeException
     */
    private function resolveTemplate(string $type): string
    {
        $template = match ($type) {
            Slide::TYPE_TITLE => 'slides/title.html.twig',
            Slide::TYPE_CLOSING => 'slides/closing.html.twig',
            Slide::TYPE_SPLIT => 'slides/split.html.twig',
            Slide::TYPE_IMAGE => 'slides/image.html.twig',
            Slide::TYPE_QUOTE => 'slides/quote.html.twig',
            Slide::TYPE_TIMELINE => 'slides/timeline.html.twig',
            Slide::TYPE_STATS => 'slides/stats.html.twig',
            Slide::TYPE_COMPARISON => 'slides/comparison.html.twig',
            Slide::TYPE_CONTENT => 'slides/content.html.twig',
            default => null,
        };

        if ($template === null) {
            $this->logger->warning('slide_builder_unsupported_type', [
                'type' => $type,
                'supported_types' => Slide::supportedTypes(),
            ]);

            throw new UnsupportedSlideTypeException($type);
        }

        return $template;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(Slide $slide): array
    {
        $content = $slide->getContent();

        return match ($slide->getType()) {
            Slide::TYPE_TITLE => $this->buildTitleContext($content),
            Slide::TYPE_CLOSING => $this->buildClosingContext($content),
            Slide::TYPE_SPLIT => $this->buildSplitContext($content),
            Slide::TYPE_IMAGE => $this->buildImageContext($content),
            Slide::TYPE_QUOTE => $this->buildQuoteContext($content),
            Slide::TYPE_TIMELINE => $this->buildTimelineContext($content),
            Slide::TYPE_STATS => $this->buildStatsContext($content),
            Slide::TYPE_COMPARISON => $this->buildComparisonContext($content),
            default => $this->buildContentContext($content),
        };
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private function buildTitleContext(array $content): array
    {
        return [
            'label' => $this->sanitizer->sanitizeText(trim((string) ($content['label'] ?? ''))),
            'title' => $this->sanitizer->sanitizeText(trim((string) ($content['title'] ?? ''))),
            'subtitle' => $this->sanitizer->sanitizeText(trim((string) ($content['subtitle'] ?? ''))),
        ];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private function buildContentContext(array $content): array
    {
        $rawItems = is_array($content['items'] ?? null) ? $content['items'] : [];
        $items = array_values(array_filter(
            array_map(fn (mixed $item): string => $this->sanitizer->sanitizeText(trim((string) $item)), $rawItems),
            static fn (string $item): bool => $item !== '',
        ));

        return [
            'title' => $this->sanitizer->sanitizeText(trim((string) ($content['title'] ?? ''))),
            'body' => $this->sanitizer->sanitizeText(trim((string) ($content['body'] ?? ''))),
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private function buildClosingContext(array $content): array
    {
        $ctaUrl = trim((string) ($content['cta_url'] ?? ''));

        return [
            'message' => $this->sanitizer->sanitizeText(trim((string) ($content['message'] ?? ''))),
            'cta_label' => $this->sanitizer->sanitizeText(trim((string) ($content['cta_label'] ?? ''))),
            'cta_url' => $this->sanitizeUrl($ctaUrl),
        ];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private function buildSplitContext(array $content): array
    {
        $rawItems = is_array($content['items'] ?? null) ? $content['items'] : [];
        $items = array_values(array_filter(
            array_map(fn (mixed $item): string => $this->sanitizer->sanitizeText(trim((string) $item)), $rawItems),
            static fn (string $item): bool => $item !== '',
        ));

        $layout = trim((string) ($content['layout'] ?? 'text-left'));
        if (!in_array($layout, ['text-left', 'text-right'], true)) {
            $layout = 'text-left';
        }

        return [
            'title' => $this->sanitizer->sanitizeText(trim((string) ($content['title'] ?? ''))),
            'body' => $this->sanitizer->sanitizeText(trim((string) ($content['body'] ?? ''))),
            'items' => $items,
            'image_url' => $this->resolveImageUrl(trim((string) ($content['image_url'] ?? ''))),
            'image_alt' => $this->sanitizer->sanitizeText(trim((string) ($content['image_alt'] ?? ''))),
            'layout' => $layout,
        ];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private function buildImageContext(array $content): array
    {
        return [
            'image_url' => $this->resolveImageUrl(trim((string) ($content['image_url'] ?? ''))),
            'image_alt' => $this->sanitizer->sanitizeText(trim((string) ($content['image_alt'] ?? ''))),
            'overlay_text' => $this->sanitizer->sanitizeText(trim((string) ($content['overlay_text'] ?? ''))),
            'caption' => $this->sanitizer->sanitizeText(trim((string) ($content['caption'] ?? ''))),
        ];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private function buildQuoteContext(array $content): array
    {
        return [
            'quote' => $this->sanitizer->sanitizeText(trim((string) ($content['quote'] ?? ''))),
            'author' => $this->sanitizer->sanitizeText(trim((string) ($content['author'] ?? ''))),
            'role' => $this->sanitizer->sanitizeText(trim((string) ($content['role'] ?? ''))),
            'source' => $this->sanitizer->sanitizeText(trim((string) ($content['source'] ?? ''))),
        ];
    }

    private function sanitizeUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        // Allow absolute HTTPS/HTTP URLs.
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        // Allow relative paths (e.g. /media/serve/... for local signed URLs).
        if (str_starts_with($url, '/')) {
            return $url;
        }

        return '';
    }

    /**
     * T237 — Resolve a content URL to a usable URL.
     *
     * "media:{id}" references are resolved to fresh signed URLs via MediaUrlResolver.
     * All other URLs are passed through sanitizeUrl for safety.
     */
    private function resolveImageUrl(string $url): string
    {
        if ($this->mediaUrlResolver->isMediaRef($url)) {
            return $this->mediaUrlResolver->resolve($url);
        }

        return $this->sanitizeUrl($url);
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private function buildTimelineContext(array $content): array
    {
        $rawItems = is_array($content['items'] ?? null) ? $content['items'] : [];

        $items = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = $this->sanitizer->sanitizeText(trim((string) ($item['label'] ?? '')));
            if ($label === '') {
                continue;
            }
            $items[] = [
                'year' => $this->sanitizer->sanitizeText(trim((string) ($item['year'] ?? ''))),
                'label' => $label,
                'description' => $this->sanitizer->sanitizeText(trim((string) ($item['description'] ?? ''))),
            ];
        }

        // T149: enforce min 2, max 6 items; clamp silently
        if (count($items) > 6) {
            $items = array_slice($items, 0, 6);
        }

        return [
            'title' => $this->sanitizer->sanitizeText(trim((string) ($content['title'] ?? ''))),
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private function buildStatsContext(array $content): array
    {
        $rawStats = is_array($content['stats'] ?? null) ? $content['stats'] : [];

        $stats = [];
        foreach ($rawStats as $stat) {
            if (!is_array($stat)) {
                continue;
            }
            $value = $this->sanitizer->sanitizeText(trim((string) ($stat['value'] ?? '')));
            $label = $this->sanitizer->sanitizeText(trim((string) ($stat['label'] ?? '')));
            if ($value === '' || $label === '') {
                continue;
            }
            $stats[] = [
                'value' => $value,
                'label' => $label,
                'detail' => $this->sanitizer->sanitizeText(trim((string) ($stat['detail'] ?? ''))),
            ];
        }

        // T149: enforce min 2, max 6 stats; clamp silently
        if (count($stats) > 6) {
            $stats = array_slice($stats, 0, 6);
        }

        return [
            'title' => $this->sanitizer->sanitizeText(trim((string) ($content['title'] ?? ''))),
            'stats' => $stats,
        ];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private function buildComparisonContext(array $content): array
    {
        return [
            'title' => $this->sanitizer->sanitizeText(trim((string) ($content['title'] ?? ''))),
            'left' => $this->buildComparisonColumn($content['left'] ?? []),
            'right' => $this->buildComparisonColumn($content['right'] ?? []),
        ];
    }

    /**
     * @param mixed $column
     *
     * @return array<string, mixed>
     */
    private function buildComparisonColumn(mixed $column): array
    {
        if (!is_array($column)) {
            return ['heading' => '', 'items' => [], 'highlight' => ''];
        }

        $rawItems = is_array($column['items'] ?? null) ? $column['items'] : [];
        $items = array_values(array_filter(
            array_map(fn (mixed $item): string => $this->sanitizer->sanitizeText(trim((string) $item)), $rawItems),
            static fn (string $item): bool => $item !== '',
        ));

        // T149: max 6 items per column; clamp silently
        if (count($items) > 6) {
            $items = array_slice($items, 0, 6);
        }

        return [
            'heading' => $this->sanitizer->sanitizeText(trim((string) ($column['heading'] ?? ''))),
            'items' => $items,
            'highlight' => $this->sanitizer->sanitizeText(trim((string) ($column['highlight'] ?? ''))),
        ];
    }
}
