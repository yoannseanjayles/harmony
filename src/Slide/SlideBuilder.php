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
        return [
            'message' => trim((string) ($content['message'] ?? '')),
            'cta_label' => trim((string) ($content['cta_label'] ?? '')),
            'cta_url' => trim((string) ($content['cta_url'] ?? '')),
        ];
    }
}
