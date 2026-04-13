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
     * Returns aggregated KPI data for the admin dashboard.
     *
     * @return array{
     *     totalExports: int,
     *     htmlTotal: int,
     *     htmlSuccesses: int,
     *     htmlRate: float,
     *     pdfTotal: int,
     *     pdfSuccesses: int,
     *     pdfRate: float,
     *     avgPdfDurationMs: float|null
     * }
     */
    public function computeAdminKpis(?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('metric')
            ->select('metric.format AS format')
            ->addSelect('COUNT(metric.id) AS totalCount')
            ->addSelect('SUM(CASE WHEN metric.wasSuccessful = true THEN 1 ELSE 0 END) AS successCount')
            ->groupBy('metric.format');

        if ($since !== null) {
            $qb->andWhere('metric.createdAt >= :since')->setParameter('since', $since);
        }

        $rows = $qb->getQuery()->getArrayResult();

        $result = [
            'totalExports' => 0,
            'htmlTotal' => 0,
            'htmlSuccesses' => 0,
            'htmlRate' => 0.0,
            'pdfTotal' => 0,
            'pdfSuccesses' => 0,
            'pdfRate' => 0.0,
            'avgPdfDurationMs' => null,
        ];

        foreach ($rows as $row) {
            $format = (string) $row['format'];
            $total = (int) $row['totalCount'];
            $successes = (int) $row['successCount'];
            $result['totalExports'] += $total;

            if ($format === ProjectExportMetric::FORMAT_HTML) {
                $result['htmlTotal'] = $total;
                $result['htmlSuccesses'] = $successes;
                $result['htmlRate'] = $this->calculateRate($successes, $total);
            } elseif ($format === ProjectExportMetric::FORMAT_PDF) {
                $result['pdfTotal'] = $total;
                $result['pdfSuccesses'] = $successes;
                $result['pdfRate'] = $this->calculateRate($successes, $total);
            }
        }

        // Average PDF duration using native SQL since DQL doesn't allow IS NOT NULL inside CASE
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT AVG(duration_ms) FROM project_export_metric WHERE format = :format AND duration_ms IS NOT NULL';
        $params = ['format' => ProjectExportMetric::FORMAT_PDF];
        if ($since !== null) {
            $sql .= ' AND created_at >= :since';
            $params['since'] = $since->format('Y-m-d H:i:s');
        }
        $avgRaw = $conn->fetchOne($sql, $params);
        $result['avgPdfDurationMs'] = $avgRaw !== null && $avgRaw !== false ? round((float) $avgRaw, 1) : null;

        return $result;
    }

    /**
     * Returns a 7-day daily export trend: [date => [total, successes, rate]].
     *
     * @return array<string, array{total: int, successes: int, rate: float}>
     */
    public function computeDailyTrend(?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('metric')
            ->select('metric.createdAt AS createdAt')
            ->addSelect('metric.wasSuccessful AS wasSuccessful');

        if ($since !== null) {
            $qb->andWhere('metric.createdAt >= :since')->setParameter('since', $since);
        }

        $rows = $qb->orderBy('metric.createdAt', 'ASC')->getQuery()->getArrayResult();

        $trend = [];
        foreach ($rows as $row) {
            /** @var \DateTimeImmutable $dt */
            $dt = $row['createdAt'];
            $day = $dt->format('Y-m-d');
            $trend[$day] ??= ['total' => 0, 'successes' => 0, 'rate' => 0.0];
            ++$trend[$day]['total'];
            if ((bool) $row['wasSuccessful']) {
                ++$trend[$day]['successes'];
            }
        }

        foreach ($trend as $day => $data) {
            $trend[$day]['rate'] = $this->calculateRate($data['successes'], $data['total']);
        }

        return $trend;
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
