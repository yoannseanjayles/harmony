<?php

namespace App\Export;

use App\Entity\MediaAsset;
use App\Entity\Project;
use App\Repository\MediaAssetRepository;
use App\Repository\SlideRepository;
use App\Slide\SlideBuilder;
use App\Storage\StorageAdapterInterface;
use App\Theme\ThemeEngine;
use Twig\Environment;

/**
 * HRM-T259 / HRM-T260 / HRM-T261 / HRM-T264 — Export Engine.
 *
 * Assembles a self-contained, single-file HTML presentation from a project's slides:
 *   1. Renders every slide via SlideBuilder (respects render cache + theme tokens).
 *   2. Inlines the Harmony CSS, theme tokens, and media assets as base64 data URIs.
 *   3. Wraps the result in the standalone.html.twig layout.
 *
 * The resulting HTML string is suitable for:
 *   - Direct download as an .html file (GET /export/{id}/html).
 *   - Forwarding to GotenbergClient for PDF conversion (GET /export/{id}/pdf).
 */
final class ExportService
{
    public function __construct(
        private readonly SlideRepository $slideRepository,
        private readonly SlideBuilder $slideBuilder,
        private readonly ThemeEngine $themeEngine,
        private readonly StorageAdapterInterface $storageAdapter,
        private readonly MediaAssetRepository $mediaAssetRepository,
        private readonly Environment $twig,
        private readonly string $harmonyCssPath,
    ) {
    }

    /**
     * Assemble a complete, self-contained HTML document for the project.
     *
     * All external resources (CSS, media images) are inlined so the HTML file
     * is portable and works without a running Harmony server.
     */
    public function exportHtml(Project $project): string
    {
        $slides = $this->slideRepository->findByProjectOrdered($project);

        $renderedSlides = [];
        foreach ($slides as $slide) {
            $renderedSlides[] = $this->slideBuilder->buildSlide($slide);
        }

        $themeStyle = $this->themeEngine->toCssBlock($project->getEffectiveThemeConfigJson());
        $harmonyCss = $this->loadHarmonyCss();
        $mediaMap   = $this->buildMediaBase64Map($project);

        return $this->twig->render('export/standalone.html.twig', [
            'project'         => $project,
            'renderedSlides'  => $renderedSlides,
            'themeStyle'      => $themeStyle,
            'harmonyCss'      => $harmonyCss,
            'mediaBase64Map'  => $mediaMap,
        ]);
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Load the Harmony CSS file contents.
     * Falls back to an empty string if the file is missing (e.g. in unit tests).
     */
    private function loadHarmonyCss(): string
    {
        if (!is_file($this->harmonyCssPath)) {
            return '';
        }

        $css = file_get_contents($this->harmonyCssPath);

        return $css === false ? '' : $css;
    }

    /**
     * Build a map of signed URL → base64 data URI for all media assets belonging to the project.
     *
     * The standalone HTML template uses this map to replace signed URL references with
     * inline data URIs so the exported file is fully self-contained.
     *
     * @return array<string, string>  signedUrl → "data:{mimeType};base64,{encoded}"
     */
    private function buildMediaBase64Map(Project $project): array
    {
        $assets = $this->mediaAssetRepository->findBy(['project' => $project]);
        $map    = [];

        foreach ($assets as $asset) {
            if (!$asset instanceof MediaAsset) {
                continue;
            }

            $signedUrl = $this->storageAdapter->getSignedUrl($asset->getStorageKey(), 3600);

            try {
                $binary      = $this->storageAdapter->get($asset->getStorageKey());
                $dataUri     = sprintf('data:%s;base64,%s', $asset->getMimeType(), base64_encode($binary));
                $map[$signedUrl] = $dataUri;
            } catch (\RuntimeException) {
                // Asset unreachable — omit from map; the HTML will show a broken image.
            }
        }

        return $map;
    }
}
