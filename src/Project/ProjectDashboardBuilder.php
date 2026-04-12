<?php

namespace App\Project;

use App\Entity\Project;
use App\Entity\ProjectExportMetric;
use App\Repository\ProjectExportMetricRepository;
use App\Repository\ProjectGenerationMetricRepository;

final class ProjectDashboardBuilder
{
    public function __construct(
        private readonly ProjectGenerationMetricRepository $projectGenerationMetricRepository,
        private readonly ProjectExportMetricRepository $projectExportMetricRepository,
    ) {
    }

    /**
     * @param list<Project> $projects
     *
     * @return array{
     *     entries: list<array{
     *         project: Project,
     *         aiCostUsd: float,
     *         htmlExports: array{successes: int, total: int, rate: float},
     *         pdfExports: array{successes: int, total: int, rate: float}
     *     }>,
     *     summary: array{
     *         visibleProjects: int,
     *         totalSlides: int,
     *         totalAiCostUsd: float,
     *         htmlExports: array{successes: int, total: int, rate: float},
     *         pdfExports: array{successes: int, total: int, rate: float}
     *     }
     * }
     */
    public function build(array $projects): array
    {
        $aiTotals = $this->projectGenerationMetricRepository->sumEstimatedCostCentsByProjects($projects);
        $exportSummary = $this->projectExportMetricRepository->summarizeByProjects($projects);

        $entries = [];
        $summary = [
            'visibleProjects' => count($projects),
            'totalSlides' => 0,
            'totalAiCostUsd' => 0.0,
            'htmlExports' => $this->defaultExportSummary(),
            'pdfExports' => $this->defaultExportSummary(),
        ];

        foreach ($projects as $project) {
            $projectId = $project->getId();
            if (!is_int($projectId)) {
                continue;
            }

            $htmlExports = $exportSummary[$projectId][ProjectExportMetric::FORMAT_HTML] ?? $this->defaultExportSummary();
            $pdfExports = $exportSummary[$projectId][ProjectExportMetric::FORMAT_PDF] ?? $this->defaultExportSummary();
            $aiCostUsd = round(((int) ($aiTotals[$projectId] ?? 0)) / 100, 2);

            $entries[] = [
                'project' => $project,
                'aiCostUsd' => round($aiCostUsd, 4),
                'htmlExports' => $htmlExports,
                'pdfExports' => $pdfExports,
            ];

            $summary['totalSlides'] += $project->getSlidesCount();
            $summary['totalAiCostUsd'] += $aiCostUsd;
            $summary['htmlExports']['successes'] += $htmlExports['successes'];
            $summary['htmlExports']['total'] += $htmlExports['total'];
            $summary['pdfExports']['successes'] += $pdfExports['successes'];
            $summary['pdfExports']['total'] += $pdfExports['total'];
        }

        $summary['totalAiCostUsd'] = round($summary['totalAiCostUsd'], 4);
        $summary['htmlExports']['rate'] = $this->calculateRate(
            $summary['htmlExports']['successes'],
            $summary['htmlExports']['total'],
        );
        $summary['pdfExports']['rate'] = $this->calculateRate(
            $summary['pdfExports']['successes'],
            $summary['pdfExports']['total'],
        );

        return [
            'entries' => $entries,
            'summary' => $summary,
        ];
    }

    /**
     * @return array{successes: int, total: int, rate: float}
     */
    private function defaultExportSummary(): array
    {
        return [
            'successes' => 0,
            'total' => 0,
            'rate' => 0.0,
        ];
    }

    private function calculateRate(int $successes, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        return round(($successes / $total) * 100, 1);
    }
}
