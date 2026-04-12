<?php

namespace App\Tests\Unit;

use App\Media\ImageVariantGenerator;
use PHPUnit\Framework\TestCase;

/**
 * T232 — Unit tests for ImageVariantGenerator.
 *
 * Covers:
 *   - T226 — thumbnail (120×90) is generated for JPEG/PNG/WebP
 *   - T227 — preview (max 800×600) is generated correctly
 *   - T228 — export (max 1920×1080) is generated correctly
 *   - T231 — GIF and SVG images are handled gracefully (null, no crash)
 *   - Fit-within: images smaller than the target box are not upscaled
 *   - Thumbnail dimensions are exactly 120×90
 */
final class ImageVariantGeneratorTest extends TestCase
{
    private ImageVariantGenerator $generator;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->generator = new ImageVariantGenerator();
        $this->tmpDir    = sys_get_temp_dir().'/harmony_variant_test_'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    // ── Thumbnail (T226) ─────────────────────────────────────────────────────

    public function testThumbnailGeneratesExactDimensions(): void
    {
        $path = $this->createJpegImage(400, 300);

        $bytes = $this->generator->generateThumbnail($path, 'image/jpeg');

        self::assertNotNull($bytes);
        [$w, $h] = $this->imageDimensions($bytes);
        self::assertSame(ImageVariantGenerator::THUMB_WIDTH, $w);
        self::assertSame(ImageVariantGenerator::THUMB_HEIGHT, $h);
    }

    public function testThumbnailFromPngGeneratesExactDimensions(): void
    {
        $path = $this->createPngImage(600, 400);

        $bytes = $this->generator->generateThumbnail($path, 'image/png');

        self::assertNotNull($bytes);
        [$w, $h] = $this->imageDimensions($bytes);
        self::assertSame(ImageVariantGenerator::THUMB_WIDTH, $w);
        self::assertSame(ImageVariantGenerator::THUMB_HEIGHT, $h);
    }

    public function testThumbnailFromSmallImageStillProducesCorrectDimensions(): void
    {
        // Source is smaller than thumbnail target — must still crop/scale to 120×90.
        $path = $this->createJpegImage(50, 30);

        $bytes = $this->generator->generateThumbnail($path, 'image/jpeg');

        self::assertNotNull($bytes);
        [$w, $h] = $this->imageDimensions($bytes);
        self::assertSame(ImageVariantGenerator::THUMB_WIDTH, $w);
        self::assertSame(ImageVariantGenerator::THUMB_HEIGHT, $h);
    }

    // ── Preview (T227) ───────────────────────────────────────────────────────

    public function testPreviewFitsWithinMaxDimensions(): void
    {
        $path = $this->createJpegImage(1600, 1200);

        $bytes = $this->generator->generatePreview($path, 'image/jpeg');

        self::assertNotNull($bytes);
        [$w, $h] = $this->imageDimensions($bytes);
        self::assertLessThanOrEqual(ImageVariantGenerator::PREVIEW_MAX_WIDTH, $w);
        self::assertLessThanOrEqual(ImageVariantGenerator::PREVIEW_MAX_HEIGHT, $h);
    }

    public function testPreviewPreservesAspectRatio(): void
    {
        // 1600×900 (16:9) should fit within 800×600 — output should be 800×450.
        $path = $this->createJpegImage(1600, 900);

        $bytes = $this->generator->generatePreview($path, 'image/jpeg');

        self::assertNotNull($bytes);
        [$w, $h] = $this->imageDimensions($bytes);
        self::assertSame(800, $w);
        self::assertSame(450, $h);
    }

    public function testPreviewDoesNotUpscaleSmallImage(): void
    {
        $path = $this->createJpegImage(200, 150);

        $bytes = $this->generator->generatePreview($path, 'image/jpeg');

        self::assertNotNull($bytes);
        [$w, $h] = $this->imageDimensions($bytes);
        self::assertSame(200, $w);
        self::assertSame(150, $h);
    }

    // ── Export (T228) ────────────────────────────────────────────────────────

    public function testExportFitsWithinMaxDimensions(): void
    {
        $path = $this->createJpegImage(3840, 2160);

        $bytes = $this->generator->generateExport($path, 'image/jpeg');

        self::assertNotNull($bytes);
        [$w, $h] = $this->imageDimensions($bytes);
        self::assertLessThanOrEqual(ImageVariantGenerator::EXPORT_MAX_WIDTH, $w);
        self::assertLessThanOrEqual(ImageVariantGenerator::EXPORT_MAX_HEIGHT, $h);
    }

    public function testExportPreservesAspectRatio(): void
    {
        // 3840×2160 (16:9) should fit to 1920×1080.
        $path = $this->createJpegImage(3840, 2160);

        $bytes = $this->generator->generateExport($path, 'image/jpeg');

        self::assertNotNull($bytes);
        [$w, $h] = $this->imageDimensions($bytes);
        self::assertSame(1920, $w);
        self::assertSame(1080, $h);
    }

    public function testExportDoesNotUpscaleSmallImage(): void
    {
        $path = $this->createJpegImage(800, 600);

        $bytes = $this->generator->generateExport($path, 'image/jpeg');

        self::assertNotNull($bytes);
        [$w, $h] = $this->imageDimensions($bytes);
        self::assertSame(800, $w);
        self::assertSame(600, $h);
    }

    // ── Graceful degradation (T231) ──────────────────────────────────────────

    public function testGifThumbnailReturnsNull(): void
    {
        $path = $this->createFakeGif();

        $result = $this->generator->generateThumbnail($path, 'image/gif');

        self::assertNull($result, 'GIF should return null — not a resizable type');
    }

    public function testGifPreviewReturnsNull(): void
    {
        $path = $this->createFakeGif();

        $result = $this->generator->generatePreview($path, 'image/gif');

        self::assertNull($result);
    }

    public function testGifExportReturnsNull(): void
    {
        $path = $this->createFakeGif();

        $result = $this->generator->generateExport($path, 'image/gif');

        self::assertNull($result);
    }

    public function testSvgThumbnailReturnsNull(): void
    {
        $path = $this->createFakeSvg();

        $result = $this->generator->generateThumbnail($path, 'image/svg+xml');

        self::assertNull($result, 'SVG should return null — not a resizable type');
    }

    public function testMissingFileThumbnailReturnsNull(): void
    {
        $result = $this->generator->generateThumbnail('/nonexistent/path/image.jpg', 'image/jpeg');

        self::assertNull($result);
    }

    public function testCorruptJpegReturnsNull(): void
    {
        $path = $this->tmpDir.'/corrupt.jpg';
        file_put_contents($path, 'this is not a valid jpeg file at all');

        $result = $this->generator->generateThumbnail($path, 'image/jpeg');

        self::assertNull($result);
    }

    // ── Output is valid JPEG ─────────────────────────────────────────────────

    public function testThumbnailOutputIsValidJpeg(): void
    {
        $path  = $this->createJpegImage(400, 300);
        $bytes = $this->generator->generateThumbnail($path, 'image/jpeg');

        self::assertNotNull($bytes);
        // JPEG magic bytes: FF D8 FF
        self::assertSame("\xFF\xD8\xFF", substr($bytes, 0, 3));
    }

    public function testPreviewOutputIsValidJpeg(): void
    {
        $path  = $this->createJpegImage(1200, 900);
        $bytes = $this->generator->generatePreview($path, 'image/jpeg');

        self::assertNotNull($bytes);
        self::assertSame("\xFF\xD8\xFF", substr($bytes, 0, 3));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function createJpegImage(int $width, int $height): string
    {
        $img  = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $img);

        $color = imagecolorallocate($img, 100, 150, 200);
        self::assertNotFalse($color);
        imagefill($img, 0, 0, $color);

        $path = $this->tmpDir."/test_{$width}x{$height}.jpg";
        imagejpeg($img, $path, 90);
        imagedestroy($img);

        return $path;
    }

    private function createPngImage(int $width, int $height): string
    {
        $img  = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $img);

        $color = imagecolorallocate($img, 200, 100, 50);
        self::assertNotFalse($color);
        imagefill($img, 0, 0, $color);

        $path = $this->tmpDir."/test_{$width}x{$height}.png";
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function createFakeGif(): string
    {
        // Minimal valid GIF87a header (47 49 46 38 37 61)
        $path = $this->tmpDir.'/test.gif';
        file_put_contents($path, "GIF87a\x01\x00\x01\x00\x00\x00\x00;\x00");

        return $path;
    }

    private function createFakeSvg(): string
    {
        $path = $this->tmpDir.'/test.svg';
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="blue"/></svg>');

        return $path;
    }

    /**
     * Decode JPEG bytes and return [width, height].
     *
     * @return array{int, int}
     */
    private function imageDimensions(string $bytes): array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'harmony_dim_test_').'.jpg';
        file_put_contents($tmpPath, $bytes);

        $info = getimagesize($tmpPath);
        @unlink($tmpPath);

        self::assertNotFalse($info, 'getimagesize() failed — bytes are not a valid image');

        return [(int) $info[0], (int) $info[1]];
    }
}
