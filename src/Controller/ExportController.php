<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Export\ExportService;
use App\Export\GotenbergException;
use App\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * T255 / HRM-T267 — ExportController: HTML and PDF export endpoints.
 *
 * Routes:
 *   GET /export/{id}/html — self-contained HTML download (T255)
 *   GET /export/{id}/pdf  — PDF via Gotenberg with HTML fallback (HRM-T267/T268/T269)
 */
#[Route('/export')]
final class ExportController extends AbstractController
{
    /**
     * T255 — Download a self-contained HTML export of a project.
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

    /**
     * HRM-T267/T268/T269 — Export a project as PDF via Gotenberg.
     *
     * On success returns the raw PDF binary with Content-Disposition: attachment.
     *
     * On Gotenberg failure (HRM-T268):
     *   - Logs the failure with structured context (HRM-T272)
     *   - Returns HTTP 503 JSON with errorCode, user-friendly message and htmlFallbackUrl (HRM-T269)
     */
    #[Route('/{id}/pdf', name: 'app_export_pdf_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exportPdf(
        int $id,
        ProjectRepository $projectRepository,
        ExportService $exportService,
        #[Autowire(service: 'monolog.logger.export')]
        LoggerInterface $logger,
    ): Response {
        $project = $this->findOwnedProjectOr404($id, $projectRepository);

        $slug     = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $project->getTitle()));
        $slug     = trim($slug, '-');
        $filename = ($slug !== '' ? $slug : 'export') . '.pdf';

        try {
            $pdfBytes = $exportService->exportPdf($project);

            $response = new Response($pdfBytes);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set(
                'Content-Disposition',
                sprintf('attachment; filename="%s"', $filename),
            );

            return $response;
        } catch (GotenbergException $e) {
            // HRM-T272 — log the failure with structured reason
            $logger->error('pdf_export_failed', [
                'project_id' => $project->getId(),
                'error_code' => $e->getErrorCode(),
                'message'    => $e->getMessage(),
            ]);

            // HRM-T268 / T269 — structured failure response with HTML fallback URL
            $htmlFallbackUrl = $this->generateUrl(
                'app_export_html_download',
                ['id' => $project->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );

            return new JsonResponse([
                'success'        => false,
                'errorCode'      => $e->getErrorCode(),
                'message'        => $e->getMessage(),
                'htmlFallbackUrl' => $htmlFallbackUrl,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
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
