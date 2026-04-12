<?php

namespace App\Media\MessageHandler;

use App\Entity\MediaAsset;
use App\Media\ImageVariantGenerator;
use App\Media\Message\GenerateImageVariantsMessage;
use App\Repository\MediaAssetRepository;
use App\Storage\StorageAdapterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * T226 / T227 / T228 / T229 / T230 / T231 — Handles asynchronous image variant generation.
 *
 * For each received message the handler:
 *  1. Loads the MediaAsset from the database.
 *  2. Retrieves the original file bytes from the StorageAdapter.
 *  3. Writes them to a temp file so GD can open them by path.
 *  4. Generates thumbnail (120×90), preview (800×600 max) and export (1920×1080 max) variants.
 *  5. Stores each variant via the StorageAdapter and saves the resulting keys on the entity.
 *
 * GIF and SVG images (and any image GD cannot decode) are skipped gracefully (T231):
 * the variant keys remain null and no exception is raised.
 */
#[AsMessageHandler]
final class GenerateImageVariantsHandler
{
    public function __construct(
        private readonly MediaAssetRepository $mediaAssetRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly StorageAdapterInterface $storageAdapter,
        private readonly ImageVariantGenerator $variantGenerator,
    ) {
    }

    public function __invoke(GenerateImageVariantsMessage $message): void
    {
        $asset = $this->mediaAssetRepository->find($message->mediaAssetId);

        if (!$asset instanceof MediaAsset) {
            // Asset was deleted between upload and handler execution — nothing to do.
            return;
        }

        $originalBytes = $this->fetchOriginalBytes($asset);
        if ($originalBytes === null) {
            return;
        }

        $tmpPath = $this->writeTempFile($originalBytes, $asset->getStorageKey());
        if ($tmpPath === null) {
            return;
        }

        try {
            $mimeType = $asset->getMimeType();
            $baseKey  = $this->stripExtension($asset->getStorageKey());

            $this->processVariant(
                $asset,
                fn () => $this->variantGenerator->generateThumbnail($tmpPath, $mimeType),
                $baseKey.'_thumb.jpg',
                'setThumbKey',
            );

            $this->processVariant(
                $asset,
                fn () => $this->variantGenerator->generatePreview($tmpPath, $mimeType),
                $baseKey.'_preview.jpg',
                'setPreviewKey',
            );

            $this->processVariant(
                $asset,
                fn () => $this->variantGenerator->generateExport($tmpPath, $mimeType),
                $baseKey.'_export.jpg',
                'setExportKey',
            );

            $this->entityManager->flush();
        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function fetchOriginalBytes(MediaAsset $asset): ?string
    {
        try {
            return $this->storageAdapter->get($asset->getStorageKey());
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function writeTempFile(string $bytes, string $storageKey): ?string
    {
        $ext     = pathinfo($storageKey, \PATHINFO_EXTENSION);
        $tmpPath = tempnam(sys_get_temp_dir(), 'harmony_variant_').'.'.$ext;

        if (file_put_contents($tmpPath, $bytes) === false) {
            return null;
        }

        return $tmpPath;
    }

    /**
     * Generate one variant, store it, and set the key on the asset.
     *
     * @param callable(): (string|null) $generator
     */
    private function processVariant(MediaAsset $asset, callable $generator, string $variantKey, string $setter): void
    {
        try {
            $bytes = $generator();
        } catch (\Throwable) {
            // Graceful degradation (T231): never let a broken image crash the handler.
            return;
        }

        if ($bytes === null) {
            return;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'harmony_var_out_').'.jpg';

        try {
            file_put_contents($tmpPath, $bytes);
            $this->storageAdapter->put($variantKey, $tmpPath, 'image/jpeg');
            $asset->$setter($variantKey);
        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    private function stripExtension(string $storageKey): string
    {
        $pos = strrpos($storageKey, '.');
        if ($pos === false) {
            return $storageKey;
        }

        return substr($storageKey, 0, $pos);
    }
}
