<?php

namespace App\Storage;

/**
 * T219 / T221 — S3-compatible storage adapter for production.
 *
 * Delegates all HTTP interactions to S3ClientInterface so the client can be
 * replaced with a mock in tests (T223).
 */
final class S3StorageAdapter implements StorageAdapterInterface
{
    public function __construct(
        private readonly S3ClientInterface $s3Client,
        private readonly string $bucket,
    ) {
    }

    /**
     * Upload the local file to the S3 bucket.
     *
     * @throws \RuntimeException on upload failure
     */
    public function put(string $storageKey, string $localPath, string $mimeType): void
    {
        $this->s3Client->upload($this->bucket, $storageKey, $localPath, $mimeType);
    }

    /**
     * Download and return the raw contents of the S3 object.
     *
     * @throws \RuntimeException if the object does not exist
     */
    public function get(string $storageKey): string
    {
        return $this->s3Client->download($this->bucket, $storageKey);
    }

    /**
     * T221 — Delete the S3 object (physical file cleanup). Idempotent.
     */
    public function delete(string $storageKey): void
    {
        $this->s3Client->delete($this->bucket, $storageKey);
    }

    /**
     * Return a pre-signed URL granting temporary GET access to the S3 object.
     */
    public function getSignedUrl(string $storageKey, int $expiresIn = 3600): string
    {
        return $this->s3Client->presign($this->bucket, $storageKey, $expiresIn);
    }
}
