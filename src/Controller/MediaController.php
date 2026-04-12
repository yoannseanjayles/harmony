<?php

namespace App\Controller;

use App\Entity\MediaAsset;
use App\Entity\Project;
use App\Entity\User;
use App\Media\InfectedFileException;
use App\Media\MediaUploadException;
use App\Media\MediaUploadService;
use App\Repository\MediaAssetRepository;
use App\Repository\ProjectRepository;
use App\Storage\LocalStorageAdapter;
use App\Storage\StorageAdapterInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * T210 / T238 / T239 — Media upload and signed-URL controller.
 *
 * Routes:
 *   POST /media/upload            — Upload a file, associate it with a project (T210)
 *   GET  /media/{id}/url          — Return a fresh signed URL for an asset (T238/T239)
 *   GET  /media/serve/{storageKey} — Serve a local file after HMAC verification (T233)
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
            'Content-Type' => $contentType,
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
