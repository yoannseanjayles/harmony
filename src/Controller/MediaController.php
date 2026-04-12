<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Media\InfectedFileException;
use App\Media\MediaUploadException;
use App\Media\MediaUploadService;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * T210 — Media upload controller.
 *
 * Exposes POST /media/upload to accept multipart/form-data file uploads for a given project.
 * Validation (MIME type, size, optional AV scan) is delegated to MediaUploadService.
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

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
