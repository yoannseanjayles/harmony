<?php

namespace App\Media;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * T213 — Exception thrown when an uploaded file is flagged by the antivirus scanner.
 */
final class InfectedFileException extends \RuntimeException
{
    public function __construct(string $threat = 'unknown')
    {
        parent::__construct(sprintf('Uploaded file contains a threat: %s', $threat));
    }
}
