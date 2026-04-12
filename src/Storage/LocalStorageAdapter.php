<?php

namespace App\Storage;

/**
 * T218 / T221 — Filesystem storage adapter for local development.
 *
 * Files are stored under $uploadDirectory and served from $publicBasePath.
 * No signing is required: getSignedUrl() returns a plain public URL.
 */
final class LocalStorageAdapter implements StorageAdapterInterface
{
    public function __construct(
        private readonly string $uploadDirectory,
        private readonly string $publicBasePath = '/uploads/media',
    ) {
    }

    /**
     * Move (or copy) the source file into the upload directory.
     *
     * @throws \RuntimeException if the file cannot be written
     */
    public function put(string $storageKey, string $localPath, string $mimeType): void
    {
        if (!is_dir($this->uploadDirectory)) {
            mkdir($this->uploadDirectory, 0o755, true);
        }

        $destination = $this->uploadDirectory.DIRECTORY_SEPARATOR.$storageKey;

        // rename() is preferred (atomic, same partition); fall back to copy+unlink for
        // cross-partition moves (e.g. PHP temp dir on a different mount than uploads).
        if (!@rename($localPath, $destination)) {
            if (!copy($localPath, $destination)) {
                throw new \RuntimeException(
                    sprintf('LocalStorageAdapter: failed to write "%s".', $storageKey),
                );
            }
            @unlink($localPath);
        }
    }

    /**
     * Return the raw file contents.
     *
     * @throws \RuntimeException if the key does not exist
     */
    public function get(string $storageKey): string
    {
        $path = $this->uploadDirectory.DIRECTORY_SEPARATOR.$storageKey;

        if (!is_file($path)) {
            throw new \RuntimeException(
                sprintf('LocalStorageAdapter: asset not found: "%s".', $storageKey),
            );
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException(
                sprintf('LocalStorageAdapter: failed to read "%s".', $storageKey),
            );
        }

        return $contents;
    }

    /**
     * T221 — Delete the physical file from the upload directory.
     *
     * Silently succeeds if the file does not exist.
     */
    public function delete(string $storageKey): void
    {
        $path = $this->uploadDirectory.DIRECTORY_SEPARATOR.$storageKey;

        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Return a plain public URL — no signing is required for local development.
     */
    public function getSignedUrl(string $storageKey, int $expiresIn = 3600): string
    {
        return rtrim($this->publicBasePath, '/').'/'.$storageKey;
    }
}
