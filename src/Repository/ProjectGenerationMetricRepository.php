<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectGenerationMetric;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectGenerationMetric>
 */
class ProjectGenerationMetricRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectGenerationMetric::class);
    }

    /**
     * @param list<Project> $projects
     *
     * @return array<int, int>
     */
    public function sumEstimatedCostCentsByProjects(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('metric')
            ->select('IDENTITY(metric.project) AS projectId')
            ->addSelect('COALESCE(SUM(metric.estimatedCostCents), 0) AS totalCost')
            ->andWhere('metric.project IN (:projects)')
            ->setParameter('projects', $projects)
            ->groupBy('metric.project')
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['projectId']] = (int) $row['totalCost'];
        }

        return $totals;
    }
}
