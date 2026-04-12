<?php

namespace App\Media;

use App\Entity\MediaAsset;
use App\Entity\Slide;

/**
 * T236 — Manages the bidirectional reference between MediaAsset and Slide.
 *
 * MediaAsset.slideRefsJson  tracks which slides use an asset (asset→slides direction).
 * Slide.mediaRefsJson       tracks which assets a slide uses  (slide→assets direction).
 *
 * Both lists are kept in sync by this service.  Persist the changed entities after calling
 * link() or unlink() using the EntityManager.
 */
final class MediaSlideLinker
{
    /**
     * T236 — Record that $slide references $asset.
     *
     * Adds $asset->getId() to $slide->mediaRefsJson  (if not already present).
     * Adds (string) $slide->getId() to $asset->slideRefsJson (if not already present).
     */
    public function link(MediaAsset $asset, Slide $slide): void
    {
        $assetId = (int) $asset->getId();
        $slideId = (string) $slide->getId();

        // Update Slide → assets direction
        $mediaRefs = $slide->getMediaRefs();
        if (!in_array($assetId, $mediaRefs, true)) {
            $mediaRefs[] = $assetId;
            $slide->setMediaRefs($mediaRefs);
        }

        // Update Asset → slides direction
        $slideRefs = $asset->getSlideRefs();
        if (!in_array($slideId, $slideRefs, true)) {
            $slideRefs[] = $slideId;
            $asset->setSlideRefs($slideRefs);
        }
    }

    /**
     * T236 — Record that $slide no longer references $asset.
     *
     * Removes $asset->getId() from $slide->mediaRefsJson.
     * Removes (string) $slide->getId() from $asset->slideRefsJson.
     */
    public function unlink(MediaAsset $asset, Slide $slide): void
    {
        $assetId = (int) $asset->getId();
        $slideId = (string) $slide->getId();

        // Update Slide → assets direction
        $slide->setMediaRefs(array_values(array_filter(
            $slide->getMediaRefs(),
            static fn (int $id): bool => $id !== $assetId,
        )));

        // Update Asset → slides direction
        $asset->setSlideRefs(array_values(array_filter(
            $asset->getSlideRefs(),
            static fn (string $id): bool => $id !== $slideId,
        )));
    }
}
