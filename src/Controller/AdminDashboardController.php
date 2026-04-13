<?php

namespace App\Controller;

use App\Repository\ProjectExportMetricRepository;
use App\Repository\ProjectGenerationMetricRepository;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
final class AdminDashboardController extends AbstractController
{
    private const PERIOD_7D = '7d';
    private const PERIOD_30D = '30d';
    private const PERIOD_ALL = 'all';

    private const ALLOWED_PERIODS = [
        self::PERIOD_7D,
        self::PERIOD_30D,
        self::PERIOD_ALL,
    ];

    #[Route('/dashboard', name: 'app_admin_dashboard', methods: ['GET'])]
    public function dashboard(
        Request $request,
        ProjectExportMetricRepository $exportMetricRepository,
        ProjectGenerationMetricRepository $generationMetricRepository,
        ProjectRepository $projectRepository,
    ): Response {
        $period = (string) $request->query->get('period', self::PERIOD_30D);
        if (!in_array($period, self::ALLOWED_PERIODS, true)) {
            $period = self::PERIOD_30D;
        }

        $since = $this->resolveSince($period);

        $exportKpis = $exportMetricRepository->computeAdminKpis($since);
        $generationKpis = $generationMetricRepository->computeAdminKpis($since);
        $dailyTrend = $exportMetricRepository->computeDailyTrend($since);
        $totalProjects = $projectRepository->countAll();

        return $this->render('admin/dashboard.html.twig', [
            'period' => $period,
            'allowedPeriods' => self::ALLOWED_PERIODS,
            'exportKpis' => $exportKpis,
            'generationKpis' => $generationKpis,
            'dailyTrend' => $dailyTrend,
            'totalProjects' => $totalProjects,
        ]);
    }

    private function resolveSince(string $period): ?\DateTimeImmutable
    {
        return match ($period) {
            self::PERIOD_7D => new \DateTimeImmutable('-7 days'),
            self::PERIOD_30D => new \DateTimeImmutable('-30 days'),
            default => null,
        };
    }
}
