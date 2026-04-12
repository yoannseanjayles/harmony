<?php

namespace App\Media;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * T213 — No-op antivirus scanner used when HARMONY_AV_ENABLED is false (default).
 *
 * Bind a real implementation via services.yaml when ClamAV or equivalent is available.
 */
final class NoOpAntivirusScanner implements AntivirusScannerInterface
{
    public function scan(UploadedFile $file): void
    {
        // No scanning performed — antivirus is disabled by configuration.
    }
}
