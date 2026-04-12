<?php

namespace App\Slide;

use App\Entity\Slide;
use Twig\Environment;

final class SlideBuilder
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function buildSlide(Slide $slide): string
    {
        $template = $this->resolveTemplate($slide->getType());
        $context = $this->buildContext($slide);

        return $this->twig->render($template, $context);
    }

    private function resolveTemplate(string $type): string
    {
        return match ($type) {
            Slide::TYPE_TITLE => 'slides/title.html.twig',
            Slide::TYPE_CLOSING => 'slides/closing.html.twig',
            Slide::TYPE_SPLIT => 'slides/split.html.twig',
            Slide::TYPE_IMAGE => 'slides/image.html.twig',
            Slide::TYPE_QUOTE => 'slides/quote.html.twig',
            Slide::TYPE_TIMELINE => 'slides/timeline.html.twig',
            Slide::TYPE_STATS => 'slides/stats.html.twig',
            Slide::TYPE_COMPARISON => 'slides/comparison.html.twig',
            default => 'slides/content.html.twig',
        };
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
            'label' => trim((string) ($content['label'] ?? '')),
            'title' => trim((string) ($content['title'] ?? '')),
            'subtitle' => trim((string) ($content['subtitle'] ?? '')),
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
            array_map(static fn (mixed $item): string => trim((string) $item), $rawItems),
            static fn (string $item): bool => $item !== '',
        ));

        return [
            'title' => trim((string) ($content['title'] ?? '')),
            'body' => trim((string) ($content['body'] ?? '')),
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
            'message' => trim((string) ($content['message'] ?? '')),
            'cta_label' => trim((string) ($content['cta_label'] ?? '')),
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
            array_map(static fn (mixed $item): string => trim((string) $item), $rawItems),
            static fn (string $item): bool => $item !== '',
        ));

        $layout = trim((string) ($content['layout'] ?? 'text-left'));
        if (!in_array($layout, ['text-left', 'text-right'], true)) {
            $layout = 'text-left';
        }

        return [
            'title' => trim((string) ($content['title'] ?? '')),
            'body' => trim((string) ($content['body'] ?? '')),
            'items' => $items,
            'image_url' => $this->sanitizeUrl(trim((string) ($content['image_url'] ?? ''))),
            'image_alt' => trim((string) ($content['image_alt'] ?? '')),
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
            'image_url' => $this->sanitizeUrl(trim((string) ($content['image_url'] ?? ''))),
            'image_alt' => trim((string) ($content['image_alt'] ?? '')),
            'overlay_text' => trim((string) ($content['overlay_text'] ?? '')),
            'caption' => trim((string) ($content['caption'] ?? '')),
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
            'quote' => trim((string) ($content['quote'] ?? '')),
            'author' => trim((string) ($content['author'] ?? '')),
            'role' => trim((string) ($content['role'] ?? '')),
            'source' => trim((string) ($content['source'] ?? '')),
        ];
    }

    private function sanitizeUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return '';
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
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $items[] = [
                'year' => trim((string) ($item['year'] ?? '')),
                'label' => $label,
                'description' => trim((string) ($item['description'] ?? '')),
            ];
        }

        // T149: enforce min 2, max 6 items; clamp silently
        if (count($items) > 6) {
            $items = array_slice($items, 0, 6);
        }

        return [
            'title' => trim((string) ($content['title'] ?? '')),
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
            $value = trim((string) ($stat['value'] ?? ''));
            $label = trim((string) ($stat['label'] ?? ''));
            if ($value === '' || $label === '') {
                continue;
            }
            $stats[] = [
                'value' => $value,
                'label' => $label,
                'detail' => trim((string) ($stat['detail'] ?? '')),
            ];
        }

        // T149: enforce min 2, max 6 stats; clamp silently
        if (count($stats) > 6) {
            $stats = array_slice($stats, 0, 6);
        }

        return [
            'title' => trim((string) ($content['title'] ?? '')),
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
            'title' => trim((string) ($content['title'] ?? '')),
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
            array_map(static fn (mixed $item): string => trim((string) $item), $rawItems),
            static fn (string $item): bool => $item !== '',
        ));

        // T149: max 6 items per column; clamp silently
        if (count($items) > 6) {
            $items = array_slice($items, 0, 6);
        }

        return [
            'heading' => trim((string) ($column['heading'] ?? '')),
            'items' => $items,
            'highlight' => trim((string) ($column['highlight'] ?? '')),
        ];
    }
}
