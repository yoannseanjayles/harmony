<?php

namespace App\Controller;

use App\Entity\MediaAsset;
use App\Entity\Project;
use App\Entity\Slide;
use App\Entity\User;
use App\Media\InfectedFileException;
use App\Media\MediaUploadException;
use App\Media\MediaUploadService;
use App\Repository\MediaAssetRepository;
use App\Repository\ProjectRepository;
use App\Repository\SlideRepository;
use App\Storage\LocalStorageAdapter;
use App\Storage\StorageAdapterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * T210 / T238 / T239 / T241 / T242 / T244 / T245 / T246 — Media upload, management and signed-URL controller.
 *
 * Routes:
 *   POST /media/upload                      — Upload a file, associate it with a project (T210)
 *   GET  /media/{id}/url                    — Return a fresh signed URL for an asset (T238/T239)
 *   GET  /media/serve/{storageKey}          — Serve a local file after HMAC verification (T233)
 *   GET  /media/project/{projectId}/assets  — List all media assets for a project (T241)
 *   POST /media/{id}/replace                — Replace an asset's file while keeping its ID (T242)
 *   POST /media/slide/{slideId}/overlay     — Persist overlay/opacity settings in slide contentJson (T244/T245/T246)
 */
#[Route('/media')]
final class MediaController extends AbstractController
{
    /**
     * T210 — Upload a media file and associate it with a project.
     *
     * Request (multipart/form-data):
     *   - file      : the file to upload
     *   - project_id: the owning project's ID
     *
     * Response 201 JSON (T215):
     * {
     *   "id":         <int>    — MediaAsset entity ID,
     *   "storageKey": <string> — UUID-based filename on disk,
     *   "previewUrl": <string> — public URL to preview the asset
     * }
     */
    #[Route('/upload', name: 'app_media_upload', methods: ['POST'])]
    public function upload(
        Request $request,
        MediaUploadService $mediaUploadService,
        ProjectRepository $projectRepository,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->requireUser();

        $projectId = $request->request->getInt('project_id');
        $project   = $projectRepository->find($projectId);

        if (!$project instanceof Project || $project->getUser()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'Project not found.'], Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('file');

        if ($file === null) {
            return $this->json(['error' => 'No file uploaded.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $mediaUploadService->upload($file, $project);
        } catch (MediaUploadException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (InfectedFileException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($result, Response::HTTP_CREATED);
    }

    /**
     * T241 — List all media assets belonging to the given project.
     *
     * Ownership is verified: only the authenticated owner of the project can list its assets.
     *
     * Response 200 JSON:
     * {
     *   "assets": [
     *     {
     *       "id":         <int>,
     *       "filename":   <string>,
     *       "mimeType":   <string>,
     *       "size":       <int>,
     *       "slideRefs":  <string[]>,
     *       "previewUrl": <string>
     *     }, ...
     *   ]
     * }
     */
    #[Route('/project/{projectId}/assets', name: 'app_media_project_assets', methods: ['GET'], requirements: ['projectId' => '\d+'])]
    public function projectAssets(
        int $projectId,
        ProjectRepository $projectRepository,
        MediaAssetRepository $mediaAssetRepository,
        StorageAdapterInterface $storageAdapter,
    ): JsonResponse {
        /** @var User $user */
        $user    = $this->requireUser();
        $project = $projectRepository->find($projectId);

        if (!$project instanceof Project || $project->getUser()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'Project not found.'], Response::HTTP_NOT_FOUND);
        }

        $assets = $mediaAssetRepository->findByProject($project);
        $items  = array_map(
            static fn (MediaAsset $a): array => [
                'id'         => $a->getId(),
                'filename'   => $a->getFilename(),
                'mimeType'   => $a->getMimeType(),
                'size'       => $a->getSize(),
                'slideRefs'  => $a->getSlideRefs(),
                'previewUrl' => $storageAdapter->getSignedUrl($a->getStorageKey()),
            ],
            $assets,
        );

        return $this->json(['assets' => $items]);
    }

    /**
     * T242 — Replace an existing media asset's file while keeping the same entity ID.
     *
     * Ownership is verified before accepting the new file.
     *
     * Request (multipart/form-data):
     *   - file: the replacement image file
     *
     * Response 200 JSON:
     * {
     *   "id":         <int>    — MediaAsset entity ID (unchanged),
     *   "storageKey": <string> — new UUID-based storage key,
     *   "previewUrl": <string> — fresh signed URL for the new file
     * }
     */
    #[Route('/{id}/replace', name: 'app_media_replace', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function replace(
        int $id,
        Request $request,
        MediaAssetRepository $mediaAssetRepository,
        MediaUploadService $mediaUploadService,
    ): JsonResponse {
        /** @var User $user */
        $user  = $this->requireUser();
        $asset = $mediaAssetRepository->find($id);

        if (!$asset instanceof MediaAsset || $asset->getProject()?->getUser()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'Asset not found.'], Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('file');

        if ($file === null) {
            return $this->json(['error' => 'No file uploaded.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $mediaUploadService->replaceAsset($file, $asset);
        } catch (MediaUploadException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (InfectedFileException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($result);
    }

    /**
     * T244 / T245 / T246 — Persist overlay colour and opacity settings in a slide's contentJson.
     *
     * Ownership is verified: the slide must belong to a project owned by the caller.
     *
     * Request (multipart/form-data):
     *   - asset_id     : <int>    — MediaAsset ID this overlay applies to
     *   - color        : <string> — CSS colour hex (e.g. "#ff0000") — empty string to clear
     *   - color_opacity: <int>    — 0–100 percentage for the colour overlay layer
     *   - img_opacity  : <int>    — 0–100 percentage for the base image opacity
     *
     * Response 200 JSON: { "success": true }
     */
    #[Route('/slide/{slideId}/overlay', name: 'app_media_slide_overlay', methods: ['POST'], requirements: ['slideId' => '\d+'])]
    public function slideOverlay(
        int $slideId,
        Request $request,
        SlideRepository $slideRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        /** @var User $user */
        $user  = $this->requireUser();
        $slide = $slideRepository->find($slideId);

        if (!$slide instanceof Slide || $slide->getProject()?->getUser()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'Slide not found.'], Response::HTTP_NOT_FOUND);
        }

        $assetId      = $request->request->getInt('asset_id');
        $color        = (string) $request->request->get('color', '');
        $colorOpacity = max(0, min(100, $request->request->getInt('color_opacity', 0)));
        $imgOpacity   = max(0, min(100, $request->request->getInt('img_opacity', 100)));

        $content = $slide->getContent();
        $content['_mediaOverlay'] = [
            'assetId'      => $assetId,
            'color'        => $color,
            'colorOpacity' => $colorOpacity,
            'imgOpacity'   => $imgOpacity,
        ];
        $slide->setContent($content);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    /**
     * T238 / T239 — Return a fresh signed URL for a MediaAsset.
     *
     * Ownership is verified: the asset must belong to a project owned by the
     * authenticated user before the signed URL is delivered.
     *
     * Response 200 JSON:
     * {
     *   "signedUrl": <string> — time-limited URL granting GET access to the asset,
     *   "expiresIn": <int>    — TTL in seconds used to generate the URL
     * }
     */
    #[Route('/{id}/url', name: 'app_media_signed_url', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function signedUrl(
        int $id,
        MediaAssetRepository $mediaAssetRepository,
        StorageAdapterInterface $storageAdapter,
    ): JsonResponse {
        /** @var User $user */
        $user  = $this->requireUser();
        $asset = $mediaAssetRepository->find($id);

        // T239 — verify the asset exists and belongs to a project owned by the caller
        if (!$asset instanceof MediaAsset || $asset->getProject()?->getUser()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'Asset not found.'], Response::HTTP_NOT_FOUND);
        }

        $expiresIn = 3600;
        $signedUrl = $storageAdapter->getSignedUrl($asset->getStorageKey(), $expiresIn);

        return $this->json([
            'signedUrl' => $signedUrl,
            'expiresIn' => $expiresIn,
        ]);
    }

    /**
     * T233 — Serve a local file after verifying the HMAC signature and TTL.
     *
     * This route is only meaningful when the local storage adapter is active (dev/CI).
     * In production the signed URLs go directly to S3; this route returns 403.
     *
     * Query parameters:
     *   expires  — Unix timestamp after which the URL is invalid
     *   sig      — HMAC-SHA256 hex digest of "{storageKey}.{expires}"
     */
    #[Route('/serve/{storageKey}', name: 'app_media_serve', methods: ['GET'])]
    public function serveMedia(
        string $storageKey,
        Request $request,
        LocalStorageAdapter $localAdapter,
        StorageAdapterInterface $storageAdapter,
    ): Response {
        $expires = (int) $request->query->get('expires', '0');
        $sig     = (string) $request->query->get('sig', '');

        if (!$localAdapter->verifySignature($storageKey, $expires, $sig)) {
            return new Response('Invalid or expired signature.', Response::HTTP_FORBIDDEN);
        }

        try {
            $content = $storageAdapter->get($storageKey);
        } catch (\RuntimeException) {
            return new Response('Asset not found.', Response::HTTP_NOT_FOUND);
        }

        // Derive a basic Content-Type from the file extension.
        $ext = strtolower((string) pathinfo($storageKey, \PATHINFO_EXTENSION));
        $contentType = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            default       => 'application/octet-stream',
        };

        return new Response($content, Response::HTTP_OK, [
            'Content-Type'  => $contentType,
            'Cache-Control' => 'private, max-age='.max(0, $expires - time()),
        ]);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
