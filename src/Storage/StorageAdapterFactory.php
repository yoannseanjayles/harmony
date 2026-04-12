<?php

namespace App\Storage;

/**
 * T220 — Factory that selects the active StorageAdapter at runtime based on the
 * APP_STORAGE_DRIVER environment variable.
 *
 * Supported values:
 *   - "local" (default) → LocalStorageAdapter  (development / CI)
 *   - "s3"              → S3StorageAdapter      (production)
 *
 * Both adapters are constructed eagerly (so their own dependencies are validated
 * at boot time), but only the selected one is actually used.
 */
final class StorageAdapterFactory
{
    public function __construct(
        private readonly LocalStorageAdapter $localAdapter,
        private readonly S3StorageAdapter $s3Adapter,
        private readonly string $driver,
    ) {
    }

    public function create(): StorageAdapterInterface
    {
        return match ($this->driver) {
            's3'    => $this->s3Adapter,
            default => $this->localAdapter,
        };
    }
}
