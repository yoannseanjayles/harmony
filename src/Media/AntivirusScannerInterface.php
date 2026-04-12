<?php

namespace App\Media;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * T213 — Interface for optional antivirus scanning of uploaded files.
 *
 * A no-op implementation is used by default. Configure HARMONY_AV_ENABLED=true
 * and bind a real implementation (e.g. ClamAV via ClamAvScanner) in services.yaml
 * to activate scanning in production.
 */
interface AntivirusScannerInterface
{
    /**
     * Scan the uploaded file for malware.
     *
     * @throws InfectedFileException if a threat is detected
     */
    public function scan(UploadedFile $file): void;
}
