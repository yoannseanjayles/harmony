<?php

namespace App\Media;

use App\Entity\MediaAsset;
use App\Entity\Project;
use App\Media\Message\GenerateImageVariantsMessage;
use App\Storage\StorageAdapterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * T210 / T211 / T212 / T214 / T215 / T230 — Handles media upload validation and persistence.
 *
 * Responsibilities:
 *  - Validate MIME type against a configurable whitelist (T211)
 *  - Validate file size against a configurable maximum (T212)
 *  - Optionally scan the file with an antivirus scanner (T213)
 *  - Rename the file using a UUID to avoid collisions and path traversal (T214)
 *  - Persist a MediaAsset entity and return the asset ID + preview URL (T215)
 *  - Delegate physical storage to the active StorageAdapterInterface (T217–T220)
 *  - Dispatch an async message to generate image variants after upload (T230)
 */
final class MediaUploadService
{
    /** @var list<string> */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /** Extension map for the MIME whitelist — used to derive the storage extension. */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AntivirusScannerInterface $antivirusScanner,
        private readonly StorageAdapterInterface $storageAdapter,
        private readonly MessageBusInterface $messageBus,
        private readonly int $maxUploadSizeBytes,
    ) {
    }

    /**
     * Validate, scan, store and persist the uploaded file.
     *
     * @throws MediaUploadException  on MIME or size validation failure
     * @throws InfectedFileException if the antivirus scanner detects a threat
     *
     * @return array{id: int, storageKey: string, previewUrl: string}
     */
    public function upload(UploadedFile $file, Project $project): array
    {
        // Handle PHP-level upload errors (e.g. UPLOAD_ERR_INI_SIZE from php.ini limit)
        $this->validatePhpUploadError($file);
        $this->validateMimeType($file);

        // Capture size before the adapter moves/uploads the file.
        $size = (int) $file->getSize();
        $this->validateSize($size);

        $this->antivirusScanner->scan($file);

        $storageKey = $this->buildStorageKey($file);
        $this->storageAdapter->put($storageKey, $file->getPathname(), (string) $file->getClientMimeType());

        $asset = (new MediaAsset())
            ->setFilename($file->getClientOriginalName())
            ->setMimeType((string) $file->getClientMimeType())
            ->setSize($size)
            ->setStorageKey($storageKey)
            ->setProject($project);

        $this->entityManager->persist($asset);
        $this->entityManager->flush();

        // T230 — Dispatch variant generation asynchronously so the upload response is not blocked.
        $this->messageBus->dispatch(new GenerateImageVariantsMessage((int) $asset->getId()));

        return [
            'id'         => (int) $asset->getId(),
            'storageKey' => $storageKey,
            'previewUrl' => $this->storageAdapter->getSignedUrl($storageKey),
        ];
    }

    /**
     * T242 — Replace an existing media asset's file with a new upload.
     *
     * Validates, stores and updates the entity, then removes the old stored file.
     * Variant keys (thumb/preview/export) are cleared so they are regenerated.
     * All slides referencing this asset will have their render caches invalidated
     * by the EntityManager flush (the asset storageKey change triggers re-render).
     *
     * @throws MediaUploadException  on MIME or size validation failure
     * @throws InfectedFileException if the antivirus scanner detects a threat
     *
     * @return array{id: int, storageKey: string, previewUrl: string}
     */
    public function replaceAsset(UploadedFile $file, MediaAsset $asset): array
    {
        $this->validatePhpUploadError($file);
        $this->validateMimeType($file);

        $size = (int) $file->getSize();
        $this->validateSize($size);

        $this->antivirusScanner->scan($file);

        $oldStorageKey = $asset->getStorageKey();
        $newStorageKey = $this->buildStorageKey($file);

        $this->storageAdapter->put($newStorageKey, $file->getPathname(), (string) $file->getClientMimeType());

        // Remove the old file; the adapter's delete() is idempotent so a missing key is safe.
        $this->storageAdapter->delete($oldStorageKey);

        $asset->setFilename($file->getClientOriginalName())
              ->setMimeType((string) $file->getClientMimeType())
              ->setSize($size)
              ->setStorageKey($newStorageKey)
              ->setThumbKey(null)
              ->setPreviewKey(null)
              ->setExportKey(null);

        $this->entityManager->flush();

        // Dispatch variant generation for the replacement file.
        $this->messageBus->dispatch(new GenerateImageVariantsMessage((int) $asset->getId()));

        return [
            'id'         => (int) $asset->getId(),
            'storageKey' => $newStorageKey,
            'previewUrl' => $this->storageAdapter->getSignedUrl($newStorageKey),
        ];
    }

    // ── private helpers ───────────────────────────────────────────────────────

    /**
     * T212 — Reject files that PHP has already flagged as too large (UPLOAD_ERR_INI_SIZE),
     * mapping the framework-level error to a user-visible MediaUploadException.
     */
    private function validatePhpUploadError(UploadedFile $file): void
    {
        if ($file->getError() === \UPLOAD_ERR_INI_SIZE) {
            throw MediaUploadException::fileTooLarge(
                (int) UploadedFile::getMaxFilesize(),
                $this->maxUploadSizeBytes,
            );
        }

        if ($file->getError() !== \UPLOAD_ERR_OK) {
            throw new MediaUploadException('File upload failed with error code: '.$file->getError());
        }
    }

    private function validateMimeType(UploadedFile $file): void
    {
        $mimeType = $file->getClientMimeType();

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw MediaUploadException::invalidMimeType(
                (string) $mimeType,
                implode(', ', self::ALLOWED_MIME_TYPES),
            );
        }
    }

    private function validateSize(int $sizeBytes): void
    {
        if ($sizeBytes > $this->maxUploadSizeBytes) {
            throw MediaUploadException::fileTooLarge($sizeBytes, $this->maxUploadSizeBytes);
        }
    }

    /**
     * T214 — Build a UUID-based storage key with the correct extension to avoid
     * filename collisions and path-traversal attacks.
     */
    private function buildStorageKey(UploadedFile $file): string
    {
        $mimeType  = $file->getClientMimeType();
        $extension = self::MIME_EXTENSIONS[$mimeType] ?? 'bin';

        return $this->generateUuidV4().'.'.$extension;
    }

    /**
     * Generate a version-4 UUID using PHP's cryptographically secure random source.
     */
    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);

        // Set version 4 (bits 12-15 of the 7th byte to 0100)
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        // Set variant bits (bits 6-7 of the 9th byte to 10)
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
