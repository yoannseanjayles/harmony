<?php

namespace App\Media;

/**
 * T225 / T226 / T227 / T228 / T231 — Generates resized image variants using PHP's native GD extension.
 *
 * Three variants are produced for each raster image:
 *  - thumbnail : 120 × 90 px  (hard crop, T226)
 *  - preview   : 800 × 600 px max (fit-within, preserves aspect ratio, T227)
 *  - export    : 1920 × 1080 px max (fit-within, preserves aspect ratio, T228)
 *
 * Non-raster formats (GIF, SVG, …) and any image that cannot be decoded are
 * handled gracefully: the method returns null and the caller skips storing that
 * variant (T231).
 */
final class ImageVariantGenerator
{
    // ── Variant dimensions ───────────────────────────────────────────────────

    public const THUMB_WIDTH   = 120;
    public const THUMB_HEIGHT  = 90;

    public const PREVIEW_MAX_WIDTH  = 800;
    public const PREVIEW_MAX_HEIGHT = 600;

    public const EXPORT_MAX_WIDTH  = 1920;
    public const EXPORT_MAX_HEIGHT = 1080;

    // ── MIME types that support GD resizing ──────────────────────────────────

    /** @var list<string> */
    private const RESIZABLE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Generate the 120×90 thumbnail by hard-cropping to fill the target box.
     *
     * @return string|null  Raw JPEG bytes, or null if the image cannot be processed.
     */
    public function generateThumbnail(string $sourcePath, string $mimeType): ?string
    {
        $src = $this->loadImage($sourcePath, $mimeType);
        if ($src === null) {
            return null;
        }

        $srcW = (int) imagesx($src);
        $srcH = (int) imagesy($src);

        // Compute crop coordinates so the image fills the target box.
        $targetW = self::THUMB_WIDTH;
        $targetH = self::THUMB_HEIGHT;

        $scale = max($targetW / $srcW, $targetH / $srcH);

        $scaledW = (int) round($srcW * $scale);
        $scaledH = (int) round($srcH * $scale);

        $cropX = (int) round(($scaledW - $targetW) / 2);
        $cropY = (int) round(($scaledH - $targetH) / 2);

        $dst = $this->createTruecolorCanvas($targetW, $targetH);
        if ($dst === false) {
            imagedestroy($src);

            return null;
        }

        imagecopyresampled($dst, $src, 0, 0, (int) round($cropX / $scale), (int) round($cropY / $scale), $scaledW, $scaledH, $srcW, $srcH);

        $result = $this->captureAsJpeg($dst);

        imagedestroy($src);
        imagedestroy($dst);

        return $result;
    }

    /**
     * Generate a fit-within preview (max 800×600 px).
     *
     * @return string|null  Raw JPEG bytes, or null if the image cannot be processed.
     */
    public function generatePreview(string $sourcePath, string $mimeType): ?string
    {
        return $this->generateFitVariant($sourcePath, $mimeType, self::PREVIEW_MAX_WIDTH, self::PREVIEW_MAX_HEIGHT);
    }

    /**
     * Generate a fit-within export variant (max 1920×1080 px).
     *
     * @return string|null  Raw JPEG bytes, or null if the image cannot be processed.
     */
    public function generateExport(string $sourcePath, string $mimeType): ?string
    {
        return $this->generateFitVariant($sourcePath, $mimeType, self::EXPORT_MAX_WIDTH, self::EXPORT_MAX_HEIGHT);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resize the source image so it fits within (maxW × maxH), preserving the aspect ratio.
     * Images already smaller than the target are returned as-is (no up-scaling).
     *
     * @return string|null  Raw JPEG bytes, or null on failure.
     */
    private function generateFitVariant(string $sourcePath, string $mimeType, int $maxW, int $maxH): ?string
    {
        $src = $this->loadImage($sourcePath, $mimeType);
        if ($src === null) {
            return null;
        }

        $srcW = (int) imagesx($src);
        $srcH = (int) imagesy($src);

        // Do not upscale.
        if ($srcW <= $maxW && $srcH <= $maxH) {
            $result = $this->captureAsJpeg($src);
            imagedestroy($src);

            return $result;
        }

        $scale = min($maxW / $srcW, $maxH / $srcH);
        $dstW  = max(1, (int) round($srcW * $scale));
        $dstH  = max(1, (int) round($srcH * $scale));

        $dst = $this->createTruecolorCanvas($dstW, $dstH);
        if ($dst === false) {
            imagedestroy($src);

            return null;
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        $result = $this->captureAsJpeg($dst);

        imagedestroy($src);
        imagedestroy($dst);

        return $result;
    }

    /**
     * Load a GD image resource from disk.
     *
     * Returns null for unsupported MIME types (GIF, SVG, …), missing files,
     * or any GD decoding failure — ensuring graceful degradation (T231).
     *
     * @return \GdImage|null
     */
    private function loadImage(string $path, string $mimeType): ?\GdImage
    {
        if (!in_array($mimeType, self::RESIZABLE_MIME_TYPES, true)) {
            return null;
        }

        if (!is_readable($path)) {
            return null;
        }

        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default      => false,
        };

        if (!$image instanceof \GdImage) {
            return null;
        }

        return $image;
    }

    /**
     * Allocate a true-colour GD canvas with a white background.
     *
     * @return \GdImage|false
     */
    private function createTruecolorCanvas(int $width, int $height): \GdImage|false
    {
        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas instanceof \GdImage) {
            return false;
        }

        // Fill with white to avoid transparent artefacts when encoding as JPEG.
        $white = imagecolorallocate($canvas, 255, 255, 255);
        if ($white !== false) {
            imagefill($canvas, 0, 0, $white);
        }

        return $canvas;
    }

    /**
     * Capture a GD image as raw JPEG bytes (quality 85).
     */
    private function captureAsJpeg(\GdImage $image): ?string
    {
        ob_start();
        $ok = imagejpeg($image, null, 85);
        $bytes = ob_get_clean();

        if (!$ok || $bytes === false || $bytes === '') {
            return null;
        }

        return $bytes;
    }
}
