<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectExportMetric;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectExportMetric>
 */
class ProjectExportMetricRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectExportMetric::class);
    }

    /**
     * @param list<Project> $projects
     *
     * @return array<int, array{html: array{successes: int, total: int, rate: float}, pdf: array{successes: int, total: int, rate: float}}>
     */
    public function summarizeByProjects(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('metric')
            ->select('IDENTITY(metric.project) AS projectId')
            ->addSelect('metric.format AS format')
            ->addSelect('COUNT(metric.id) AS totalCount')
            ->addSelect('SUM(CASE WHEN metric.wasSuccessful = true THEN 1 ELSE 0 END) AS successCount')
            ->andWhere('metric.project IN (:projects)')
            ->setParameter('projects', $projects)
            ->groupBy('metric.project, metric.format')
            ->getQuery()
            ->getArrayResult();

        $summary = [];
        foreach ($rows as $row) {
            $projectId = (int) $row['projectId'];
            $format = (string) $row['format'];
            $total = (int) $row['totalCount'];
            $successes = (int) $row['successCount'];

            $summary[$projectId] ??= [
                ProjectExportMetric::FORMAT_HTML => $this->defaultEntry(),
                ProjectExportMetric::FORMAT_PDF => $this->defaultEntry(),
            ];

            $summary[$projectId][$format] = [
                'successes' => $successes,
                'total' => $total,
                'rate' => $this->calculateRate($successes, $total),
            ];
        }

        return $summary;
    }

    /**
     * @return array{successes: int, total: int, rate: float}
     */
    private function defaultEntry(): array
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
