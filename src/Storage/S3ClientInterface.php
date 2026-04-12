<?php

namespace App\Storage;

/**
 * T219 — Minimal contract for S3-compatible object storage clients.
 *
 * Keeping the interface minimal allows easy mocking in unit tests (T223) and
 * lets the real NativeS3Client be swapped for alternative implementations
 * (e.g. the official AWS SDK) without touching S3StorageAdapter.
 */
interface S3ClientInterface
{
    /**
     * Upload a local file to the S3 bucket under the given key.
     *
     * @throws \RuntimeException on upload failure
     */
    public function upload(string $bucket, string $key, string $filePath, string $contentType): void;

    /**
     * Download and return the raw contents of an S3 object.
     *
     * @throws \RuntimeException if the object does not exist or the download fails
     */
    public function download(string $bucket, string $key): string;

    /**
     * T221 — Delete an S3 object. Idempotent: does not throw if the key is absent.
     *
     * @throws \RuntimeException on unexpected HTTP errors
     */
    public function delete(string $bucket, string $key): void;

    /**
     * Generate a pre-signed URL granting temporary GET access to the object.
     *
     * @param int $expiresIn Validity in seconds (default: 3600)
     */
    public function presign(string $bucket, string $key, int $expiresIn): string;
}
