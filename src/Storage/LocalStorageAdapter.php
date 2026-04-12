<?php

namespace App\Storage;

/**
 * T218 / T221 / T233 — Filesystem storage adapter for local development.
 *
 * Files are stored under $uploadDirectory and served from $publicBasePath.
 *
 * Signed URLs (T233): when $hmacSecret is provided, getSignedUrl() generates a
 * HMAC-SHA256-signed URL served by MediaController::serveMedia().
 * When no secret is configured, a plain public path is returned (dev-only fallback).
 */
final class LocalStorageAdapter implements StorageAdapterInterface
{
    public function __construct(
        private readonly string $uploadDirectory,
        private readonly string $publicBasePath = '/uploads/media',
        private readonly ?string $hmacSecret = null,
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
     * T233 — Return a signed URL granting temporary access to the local file.
     *
     * If no HMAC secret is configured (dev/CI without signing), a plain public
     * path ("/uploads/media/{key}") is returned for backward compatibility.
     *
     * With a secret the URL has the form:
     *   /media/serve/{storageKey}?expires={unix_timestamp}&sig={hmac_sha256_hex}
     * where HMAC is computed over "{storageKey}.{expires}" with the configured secret.
     */
    public function getSignedUrl(string $storageKey, int $expiresIn = 3600): string
    {
        if ($this->hmacSecret === null) {
            return rtrim($this->publicBasePath, '/').'/'.$storageKey;
        }

        $expires = time() + $expiresIn;
        $sig     = hash_hmac('sha256', $storageKey.'.'.$expires, $this->hmacSecret);

        return '/media/serve/'.rawurlencode($storageKey).'?expires='.$expires.'&sig='.$sig;
    }

    /**
     * T233 — Verify the HMAC signature and expiry for a local signed URL.
     *
     * Returns true only when:
     *   - an HMAC secret is configured,
     *   - the URL has not expired, and
     *   - the signature is cryptographically valid (constant-time comparison).
     */
    public function verifySignature(string $storageKey, int $expires, string $sig): bool
    {
        if ($this->hmacSecret === null) {
            return false;
        }

        if ($expires < time()) {
            return false;
        }

        $expected = hash_hmac('sha256', $storageKey.'.'.$expires, $this->hmacSecret);

        return hash_equals($expected, $sig);
    }
}
