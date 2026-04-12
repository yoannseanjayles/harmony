<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\ProjectExportMetric;
use App\Entity\User;
use App\Export\ExportService;
use App\Export\GotenbergClientInterface;
use App\Export\GotenbergTimeoutException;
use App\Export\GotenbergUnavailableException;
use App\Project\ProjectMetricsRecorder;
use App\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HRM-T262 — Exposes two download endpoints for a project:
 *
 *   GET /export/{id}/html  → self-contained HTML file download
 *   GET /export/{id}/pdf   → PDF file download via Gotenberg
 *
 * Both endpoints enforce ownership (the project must belong to the authenticated user)
 * and record export metrics via ProjectMetricsRecorder.
 */
#[Route('/export')]
final class ExportController extends AbstractController
{
    public function __construct(
        private readonly ExportService $exportService,
        private readonly GotenbergClientInterface $gotenbergClient,
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectMetricsRecorder $projectMetricsRecorder,
        #[Autowire(service: 'monolog.logger.ai')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * HRM-T262 — Download a self-contained HTML export of the project.
     */
    #[Route('/{id}/html', name: 'app_export_html_download', methods: ['GET'])]
    public function downloadHtml(int $id): Response
    {
        $project = $this->resolveOwnedProject($id);

        $html = $this->exportService->exportHtml($project);
        $filename = $this->sanitizeFilename($project->getTitle()) . '.html';

        $this->projectMetricsRecorder->recordExport($project, ProjectExportMetric::FORMAT_HTML, true);

        return new Response($html, Response::HTTP_OK, [
            'Content-Type'        => 'text/html; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    /**
     * HRM-T262 / HRM-T263 / HRM-T264 — Download a PDF export of the project via Gotenberg.
     *
     * The HTML is assembled by ExportService and forwarded to Gotenberg.
     * On timeout or Gotenberg failure the user receives a 503 JSON response and the
     * metric is recorded as a failure for KPI tracking.
     */
    #[Route('/{id}/pdf', name: 'app_export_pdf_download', methods: ['GET'])]
    public function downloadPdf(int $id): Response
    {
        $project = $this->resolveOwnedProject($id);

        $startTime = microtime(true);
        $html      = $this->exportService->exportHtml($project);
        $filename  = $this->sanitizeFilename($project->getTitle()) . '.pdf';

        try {
            $pdfBinary = $this->gotenbergClient->convertHtmlToPdf($html);
        } catch (GotenbergTimeoutException $e) {
            // HRM-T263 — Graceful timeout handling
            $this->logger->error('pdf_export_timeout', [
                'project_id' => $project->getId(),
                'duration_ms' => $this->elapsedMs($startTime),
                'error' => $e->getMessage(),
            ]);

            $this->projectMetricsRecorder->recordExport($project, ProjectExportMetric::FORMAT_PDF, false);

            return $this->json([
                'status'    => 'error',
                'code'      => 'timeout',
                'message'   => 'Le service PDF est temporairement indisponible. Veuillez réessayer dans quelques instants.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (GotenbergUnavailableException $e) {
            $this->logger->error('pdf_export_unavailable', [
                'project_id' => $project->getId(),
                'duration_ms' => $this->elapsedMs($startTime),
                'error' => $e->getMessage(),
            ]);

            $this->projectMetricsRecorder->recordExport($project, ProjectExportMetric::FORMAT_PDF, false);

            return $this->json([
                'status'    => 'error',
                'code'      => 'unavailable',
                'message'   => 'Le service PDF a retourné une erreur. Veuillez réessayer ou exporter en HTML.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $pdfSize     = strlen($pdfBinary);
        $durationMs  = $this->elapsedMs($startTime);

        // HRM-T264 — Log job metrics (duration, status, file size) for KPI observability
        $this->logger->info('pdf_export_success', [
            'project_id'   => $project->getId(),
            'duration_ms'  => $durationMs,
            'pdf_size_bytes' => $pdfSize,
        ]);

        $this->projectMetricsRecorder->recordExport($project, ProjectExportMetric::FORMAT_PDF, true);

        return new Response($pdfBinary, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length'      => (string) $pdfSize,
        ]);
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private function resolveOwnedProject(int $id): Project
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $project = $this->projectRepository->findOwnedProject($id, $user);
        if (!$project instanceof Project) {
            throw $this->createNotFoundException();
        }

        return $project;
    }

    private function sanitizeFilename(string $title): string
    {
        $name = preg_replace('/[^a-z0-9\-_]+/i', '-', $title) ?? 'export';
        $name = trim($name, '-');

        return $name !== '' ? substr($name, 0, 80) : 'export';
    }

    private function elapsedMs(float $startTime): int
    {
        return (int) round((microtime(true) - $startTime) * 1000);
    }
}
