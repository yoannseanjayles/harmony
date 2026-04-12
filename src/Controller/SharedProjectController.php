<?php

namespace App\Controller;

use App\Entity\User;
use App\Project\ProjectShareLinkGenerator;
use App\Repository\ProjectRepository;
use App\Security\SecurityLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class SharedProjectController extends AbstractController
{
    #[Route('/share/{token}', name: 'app_project_share_show', methods: ['GET'])]
    #[Route('/shared/projects/{token}', name: 'app_shared_project_access', methods: ['GET'])]
    public function show(
        string $token,
        Request $request,
        ProjectRepository $projectRepository,
        ProjectShareLinkGenerator $projectShareLinkGenerator,
        SecurityLogger $securityLogger,
        Security $security,
    ): Response {
        $project = $projectRepository->findSharedProjectByToken($token);
        if ($project === null) {
            throw $this->createNotFoundException();
        }

        $expiresAt = $project->getShareExpiresAt();
        if (!$project->isPublic() || $project->getShareToken() === null || !$expiresAt instanceof \DateTimeImmutable) {
            throw $this->createNotFoundException();
        }

        if ($expiresAt < new \DateTimeImmutable()) {
            throw new GoneHttpException('Shared project link has expired.');
        }

        if (!$projectShareLinkGenerator->isValid($token, $expiresAt)) {
            throw $this->createNotFoundException();
        }

        $user = $security->getUser();

        $securityLogger->logSharedProjectAccess(
            $user instanceof User ? $user->getId() : null,
            $request->getClientIp(),
            $token,
        );

        return $this->render('shared/project_access.html.twig', [
            'project' => $project,
            'shareUrl' => $request->getUri(),
        ]);
    }
}
