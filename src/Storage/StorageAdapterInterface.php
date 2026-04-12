<?php

namespace App\Storage;

/**
 * T217 — Defines the contract for all storage backends (local filesystem and S3-compatible).
 *
 * Two implementations are provided:
 *  - LocalStorageAdapter  : stores files on the local filesystem (development)
 *  - S3StorageAdapter     : stores files in an S3-compatible object store (production)
 *
 * The active adapter is selected at boot time via the APP_STORAGE_DRIVER environment variable.
 */
interface StorageAdapterInterface
{
    /**
     * Store a file in the storage backend.
     *
     * @param string $storageKey   The unique identifier used to address the file (e.g. a UUID key).
     * @param string $localPath    Absolute path to the source file on the local filesystem.
     * @param string $mimeType     MIME type of the file (e.g. "image/jpeg").
     *
     * @throws \RuntimeException on write failure
     */
    public function put(string $storageKey, string $localPath, string $mimeType): void;

    /**
     * Retrieve the raw contents of a stored file.
     *
     * @throws \RuntimeException if the key does not exist or the read fails
     */
    public function get(string $storageKey): string;

    /**
     * T221 — Delete a stored file and clean up the underlying physical resource.
     *
     * Implementations must be idempotent: deleting a non-existent key must not throw.
     */
    public function delete(string $storageKey): void;

    /**
     * Return a URL that grants (temporary) access to the stored file.
     *
     * For local storage this is a plain public path ("/uploads/media/{key}").
     * For S3 this is an AWS pre-signed URL valid for $expiresIn seconds.
     */
    public function getSignedUrl(string $storageKey, int $expiresIn = 3600): string;
}
