<?php

namespace App\Media\Message;

/**
 * T230 — Async message dispatched after a file upload to trigger image variant generation.
 *
 * Contains only the MediaAsset ID so the handler can reload it from the database
 * and avoid serialising large entity graphs across the message bus.
 */
final class GenerateImageVariantsMessage
{
    public function __construct(
        public readonly int $mediaAssetId,
    ) {
    }
}
