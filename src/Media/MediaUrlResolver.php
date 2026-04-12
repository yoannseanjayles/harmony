<?php

namespace App\Media;

use App\Repository\MediaAssetRepository;
use App\Storage\StorageAdapterInterface;

/**
 * T237 — Resolves "media:{id}" content references to fresh signed URLs.
 *
 * Slide contentJson fields (e.g. image_url) may store a symbolic reference of the
 * form "media:{assetId}" instead of a hard-coded URL.  At render time SlideBuilder
 * delegates resolution to this service so that every render uses a fresh signed URL
 * (important when the underlying storage adapter produces time-limited URLs).
 *
 * Plain HTTPS/HTTP URLs and relative paths are passed through unchanged.
 */
final class MediaUrlResolver
{
    /** Prefix used in contentJson to denote a MediaAsset reference. */
    public const SCHEME = 'media:';

    public function __construct(
        private readonly MediaAssetRepository $mediaAssetRepository,
        private readonly StorageAdapterInterface $storageAdapter,
        private readonly int $signedUrlTtlSeconds = 3600,
    ) {
    }

    /**
     * Resolve a content URL to a usable URL.
     *
     * - "media:{id}"  → signed URL for the asset's primary storageKey
     * - anything else → returned unchanged (the caller is responsible for
     *                   sanitising non-media URLs before rendering)
     */
    public function resolve(string $url): string
    {
        if (!str_starts_with($url, self::SCHEME)) {
            return $url;
        }

        $id    = (int) substr($url, strlen(self::SCHEME));
        $asset = $this->mediaAssetRepository->find($id);

        if ($asset === null) {
            return '';
        }

        return $this->storageAdapter->getSignedUrl($asset->getStorageKey(), $this->signedUrlTtlSeconds);
    }

    /**
     * Return true when $url is a "media:{id}" reference that should be resolved.
     */
    public function isMediaRef(string $url): bool
    {
        return str_starts_with($url, self::SCHEME);
    }
}
