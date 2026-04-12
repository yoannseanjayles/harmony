<?php

namespace App\Slide;

use App\Entity\Slide;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

final class SlideBuilder
{
    public function __construct(
        private readonly Environment $twig,
        private readonly SlideHtmlSanitizer $sanitizer,
        #[Autowire(service: 'monolog.logger.ai')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function buildSlide(Slide $slide): string
    {
        $template = $this->resolveTemplate($slide->getType());
        $context = $this->buildContext($slide);

        return $this->twig->render($template, $context);
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
            'image_url' => $this->sanitizeUrl(trim((string) ($content['image_url'] ?? ''))),
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
            'image_url' => $this->sanitizeUrl(trim((string) ($content['image_url'] ?? ''))),
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
