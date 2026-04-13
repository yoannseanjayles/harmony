<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\ProjectExportMetric;
use App\Entity\User;
use App\Ops\OpsLogger;
use App\Project\ProjectMetricsRecorder;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class SecurityActionController extends AbstractController
{
    public function __construct(private readonly OpsLogger $opsLogger)
    {
    }

    #[Route('/api/preferences', name: 'app_api_preferences', methods: ['POST'], defaults: ['_csrf_header_token_id' => 'api_mutation'])]
    public function savePreferences(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'channel' => 'api',
        ]);
    }

    #[Route('/ai/prompt', name: 'app_ai_prompt', methods: ['POST'], defaults: ['_csrf_header_token_id' => 'api_mutation'])]
    public function queuePrompt(Request $request, ProjectRepository $projectRepository, ProjectMetricsRecorder $projectMetricsRecorder): JsonResponse
    {
        $project = $this->resolveOwnedProjectFromRequest($request, $projectRepository);
        $provider = (string) $request->request->get('provider', $project?->getProvider() ?? 'openai');
        $model = (string) $request->request->get('model', $project?->getModel() ?? 'default');
        $estimatedCostUsd = (string) $request->request->get('estimatedCostUsd', '0.0000');

        if ($project instanceof Project) {
            $projectMetricsRecorder->recordGeneration($project, $provider, $model, $estimatedCostUsd);
        }

        return $this->json([
            'status' => 'accepted',
            'channel' => 'ai',
            'provider' => $provider,
            'model' => $model,
            'projectId' => $project?->getId(),
            'estimatedCostUsd' => number_format(max(0.0, (float) $estimatedCostUsd), 4, '.', ''),
        ], JsonResponse::HTTP_ACCEPTED);
    }

    #[Route('/export/html', name: 'app_export_html', methods: ['POST'], defaults: ['_csrf_header_token_id' => 'api_mutation'])]
    public function exportHtml(Request $request, ProjectRepository $projectRepository, ProjectMetricsRecorder $projectMetricsRecorder): JsonResponse
    {
        return $this->handleExportRequest($request, ProjectExportMetric::FORMAT_HTML, $projectRepository, $projectMetricsRecorder);
    }

    #[Route('/export/pdf', name: 'app_export_pdf', methods: ['POST'], defaults: ['_csrf_header_token_id' => 'api_mutation'])]
    public function exportPdf(Request $request, ProjectRepository $projectRepository, ProjectMetricsRecorder $projectMetricsRecorder): JsonResponse
    {
        return $this->handleExportRequest($request, ProjectExportMetric::FORMAT_PDF, $projectRepository, $projectMetricsRecorder);
    }

    private function handleExportRequest(Request $request, string $format, ProjectRepository $projectRepository, ProjectMetricsRecorder $projectMetricsRecorder): JsonResponse
    {
        $project = $this->resolveOwnedProjectFromRequest($request, $projectRepository);
        $wasSuccessful = filter_var($request->request->get('successful', 'true'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        $durationMs = $request->request->getInt('durationMs', 0) ?: null;
        $failureReason = $wasSuccessful ? null : ((string) $request->request->get('failureReason', '') ?: null);

        if ($project instanceof Project) {
            $projectMetricsRecorder->recordExport($project, $format, $wasSuccessful, $durationMs, $failureReason);
        }

        if (!$wasSuccessful) {
            $reason = (string) $request->request->get('reason', 'unknown');
            $durationMs = max(0, $request->request->getInt('durationMs', 0));
            $this->opsLogger->logExportFailure($format, $reason, $durationMs);
        }

        return $this->json([
            'status' => $wasSuccessful ? 'accepted' : 'failed',
            'channel' => 'export',
            'format' => $format,
            'projectId' => $project?->getId(),
            'wasSuccessful' => $wasSuccessful,
        ], JsonResponse::HTTP_ACCEPTED);
    }

    private function resolveOwnedProjectFromRequest(Request $request, ProjectRepository $projectRepository): ?Project
    {
        $projectId = $request->request->getInt('projectId', 0);
        if ($projectId <= 0) {
            return null;
        }

        $project = $projectRepository->findOwnedProject($projectId, $this->requireUser());
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
