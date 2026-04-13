<?php

namespace App\Export;

use App\Entity\MediaAsset;
use App\Entity\Project;
use App\Entity\Slide;
use App\Repository\MediaAssetRepository;
use App\Repository\SlideRepository;
use App\Slide\SlideBuilder;
use App\Storage\StorageAdapterInterface;
use App\Theme\ThemeEngine;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * T249 — ExportService: generates a self-contained HTML file for a project.
 *
 * Design:
 *   - exportHtml() renders all slides in order, injects theme tokens, inlines
 *     media assets as base64 data URIs (T252), and produces a single HTML file
 *     that works offline (T256).
 *   - exportPdf() delegates to GotenbergClientInterface after building the same HTML (HRM-T267).
 *   - The standalone template (T254) includes keyboard navigation between slides.
 *   - The endpoint (T255) is at GET /export/{id}/html and triggers a download.
 */
final class ExportService
{
    public function __construct(
        private readonly SlideBuilder $slideBuilder,
        private readonly ThemeEngine $themeEngine,
        private readonly SlideRepository $slideRepository,
        private readonly MediaAssetRepository $mediaAssetRepository,
        private readonly StorageAdapterInterface $storageAdapter,
        private readonly Environment $twig,
        private readonly GotenbergClientInterface $gotenbergClient,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%harmony.media.signed_url_ttl%')]
        private readonly int $signedUrlTtlSeconds,
    ) {
    }

    /**
     * T249 — Export a project to a self-contained HTML string.
     *
     * - All slides are rendered in position order (T250).
     * - Theme CSS tokens are injected into the exported file (T251).
     * - Referenced media assets are inlined as base64 data URIs (T252).
     * - The harmony.css is embedded for offline use (T256).
     */
    public function exportHtml(Project $project): string
    {
        // T250 — load all slides in position order
        $slides = $this->slideRepository->findByProjectOrdered($project);

        // T252 — pre-load all media assets referenced across slides
        $mediaAssetCache = $this->preloadMediaAssets($slides);

        // T250 — render each slide; T252 — inline media as base64
        $renderedSlides = [];
        foreach ($slides as $slide) {
            try {
                $html = $this->slideBuilder->buildSlide($slide);
                $html = $this->inlineMediaBase64($html, $slide, $mediaAssetCache);
                $renderedSlides[] = $html;
            } catch (\Throwable) {
                // Skip unrenderable slides — same graceful degradation as ProjectController
            }
        }

        // T251 — get the project theme CSS tokens block
        $themeJson    = $project->getEffectiveThemeConfigJson();
        $themeCssBlock = $this->themeEngine->toCssBlock($themeJson);

        // T256 — embed harmony.css so the file works offline
        $harmonyCss = $this->readHarmonyCss();

        // T253 — inline woff2 fonts found in public/fonts (if any)
        $harmonyCss = $this->inlineFontsInCss($harmonyCss);

        // T254 — render the standalone template
        return $this->twig->render('export/standalone.html.twig', [
            'project'        => $project,
            'renderedSlides' => $renderedSlides,
            'themeCssBlock'  => $themeCssBlock,
            'harmonyCss'     => $harmonyCss,
        ]);
    }

    /**
     * HRM-T267 — Export a project to a PDF by delegating to Gotenberg.
     *
     * Builds the self-contained HTML via exportHtml(), then sends it to
     * GotenbergClientInterface::convertHtmlToPdf().
     *
     * @return string Raw PDF bytes
     *
     * @throws GotenbergException on any Gotenberg failure (timeout / 5xx / connection)
     */
    public function exportPdf(Project $project): string
    {
        $html     = $this->exportHtml($project);
        $slug     = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $project->getTitle()));
        $slug     = trim($slug, '-');
        $filename = ($slug !== '' ? $slug : 'export') . '.html';

        return $this->gotenbergClient->convertHtmlToPdf($html, $filename);
    }

    /**
     * T252 — Pre-load all media assets referenced across the given slides.
     *
     * Returns a map of:
     *   assetId => array{asset: MediaAsset, dataUri: string, storageKey: string}
     *
     * Uses the exportKey variant of the asset when available (highest resolution),
     * falling back to the primary storageKey.
     *
     * @param list<Slide> $slides
     *
     * @return array<int, array{asset: MediaAsset, dataUri: string, storageKey: string}>
     */
    private function preloadMediaAssets(array $slides): array
    {
        $cache = [];

        foreach ($slides as $slide) {
            foreach ($slide->getMediaRefs() as $assetId) {
                if (isset($cache[$assetId])) {
                    continue;
                }

                $asset = $this->mediaAssetRepository->find($assetId);
                if (!$asset instanceof MediaAsset) {
                    continue;
                }

                // T252 — use export-resolution variant when available; fall back to original
                $storageKey = $asset->getExportKey() ?? $asset->getStorageKey();

                try {
                    $bytes   = $this->storageAdapter->get($storageKey);
                    $dataUri = 'data:' . $asset->getMimeType() . ';base64,' . base64_encode($bytes);
                    $cache[$assetId] = [
                        'asset'      => $asset,
                        'dataUri'    => $dataUri,
                        'storageKey' => $storageKey,
                    ];
                } catch (\RuntimeException) {
                    // Asset file not found — skip; image will be missing in the export
                }
            }
        }

        return $cache;
    }

    /**
     * T252 — Replace signed media URLs in rendered slide HTML with base64 data URIs.
     *
     * After SlideBuilder renders a slide, `media:{id}` references are already resolved
     * to signed storage URLs.  This method regenerates those same signed URLs (within
     * the same request, so the expires timestamp is identical) and replaces them with
     * the pre-computed base64 data URIs so the export file works offline.
     *
     * @param array<int, array{asset: MediaAsset, dataUri: string, storageKey: string}> $mediaAssetCache
     */
    private function inlineMediaBase64(string $html, Slide $slide, array $mediaAssetCache): string
    {
        $mediaRefs = $slide->getMediaRefs();
        if ($mediaRefs === [] || $mediaAssetCache === []) {
            return $html;
        }

        $replacements = [];
        foreach ($mediaRefs as $assetId) {
            if (!isset($mediaAssetCache[$assetId])) {
                continue;
            }

            $entry      = $mediaAssetCache[$assetId];
            $storageKey = $entry['storageKey'];
            $dataUri    = $entry['dataUri'];

            try {
                // Regenerate the signed URL with the same TTL SlideBuilder used.
                // Both calls happen within the same HTTP request so the expires
                // timestamp is identical, producing the same signature string.
                $signedUrl = $this->storageAdapter->getSignedUrl($storageKey, $this->signedUrlTtlSeconds);
                $replacements[$signedUrl] = $dataUri;
                // Also handle the HTML-encoded variant (e.g. &amp; in query strings)
                $encoded = htmlspecialchars($signedUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($encoded !== $signedUrl) {
                    $replacements[$encoded] = $dataUri;
                }
            } catch (\RuntimeException) {
                // skip
            }
        }

        return $replacements !== [] ? strtr($html, $replacements) : $html;
    }

    /**
     * T253 — Inline woff2 font files referenced in the given CSS string as base64.
     *
     * Scans the CSS for `url(...)` references pointing to `.woff2` files under the
     * public directory and replaces each with a `data:font/woff2;base64,...` URI.
     * Files that cannot be found on disk are left unchanged.
     */
    private function inlineFontsInCss(string $css): string
    {
        if ($css === '') {
            return $css;
        }

        return (string) preg_replace_callback(
            '/url\(["\']?([^)"\']+\.woff2)["\']?\)/i',
            function (array $matches) use ($css): string {
                $fontUrl = $matches[1];

                // Resolve relative URL to an absolute filesystem path
                $fontPath = str_starts_with($fontUrl, '/')
                    ? $this->projectDir . '/public' . $fontUrl
                    : $this->projectDir . '/public/' . $fontUrl;

                if (!is_file($fontPath)) {
                    return $matches[0]; // leave unchanged
                }

                $bytes = file_get_contents($fontPath);
                if ($bytes === false) {
                    return $matches[0];
                }

                return 'url("data:font/woff2;base64,' . base64_encode($bytes) . '")';
            },
            $css,
        );
    }

    private function readHarmonyCss(): string
    {
        $cssPath = $this->projectDir . '/public/theme/harmony.css';
        if (!is_file($cssPath)) {
            return '';
        }

        return (string) file_get_contents($cssPath);
    }
}
