<?php

namespace App\Media;

/**
 * T211 / T212 — Exception thrown when an uploaded file fails validation.
 */
final class MediaUploadException extends \RuntimeException
{
    public static function invalidMimeType(string $mimeType, string $allowedList): self
    {
        return new self(sprintf(
            'MIME type "%s" is not allowed. Accepted types: %s.',
            $mimeType,
            $allowedList,
        ));
    }

    public static function fileTooLarge(int $sizeBytes, int $maxBytes): self
    {
        return new self(sprintf(
            'File size %d bytes exceeds the maximum allowed size of %d bytes.',
            $sizeBytes,
            $maxBytes,
        ));
    }
}
