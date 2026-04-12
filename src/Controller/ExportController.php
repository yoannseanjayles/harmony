<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Export\ExportService;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * T255 — ExportController: triggers a single-file HTML download for a project.
 *
 * Route: GET /export/{id}/html
 *
 * The endpoint verifies project ownership, delegates rendering to ExportService,
 * and returns the HTML as a Content-Disposition: attachment response so the
 * browser prompts a file download.
 */
#[Route('/export')]
final class ExportController extends AbstractController
{
    /**
     * T255 — Download a self-contained HTML export of a project.
     *
     * @param int             $id                Project ID
     * @param ProjectRepository $projectRepository
     * @param ExportService   $exportService
     */
    #[Route('/{id}/html', name: 'app_export_html_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exportHtml(int $id, ProjectRepository $projectRepository, ExportService $exportService): Response
    {
        $project = $this->findOwnedProjectOr404($id, $projectRepository);

        $html = $exportService->exportHtml($project);

        // Build a URL-safe filename from the project title
        $slug     = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $project->getTitle()));
        $slug     = trim($slug, '-');
        $filename = ($slug !== '' ? $slug : 'export') . '.html';

        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s"', $filename),
        );

        return $response;
    }

    private function findOwnedProjectOr404(int $id, ProjectRepository $projectRepository): Project
    {
        $project = $projectRepository->findOwnedProject($id, $this->requireUser());
        if (!$project instanceof Project) {
            throw $this->createNotFoundException();
        }

        return $project;
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
