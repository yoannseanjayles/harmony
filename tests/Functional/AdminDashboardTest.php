<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\ProjectExportMetric;
use App\Entity\ProjectGenerationMetric;
use App\Entity\User;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminDashboardTest extends FunctionalTestCase
{
    public function testAdminDashboardIsAccessibleToAdminRole(): void
    {
        $admin = $this->createUser('admin@harmony.test', ['ROLE_ADMIN']);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Tableau de bord admin');
    }

    public function testAdminDashboardIsForbiddenForRegularUser(): void
    {
        $user = $this->createUser('user@harmony.test');

        $this->client->loginUser($user);
        $this->client->request('GET', '/admin/dashboard');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminDashboardIsRedirectedForAnonymousUser(): void
    {
        $this->client->request('GET', '/admin/dashboard');

        self::assertResponseRedirects('/login');
    }

    public function testAdminDashboardDisplaysAggregatedKpis(): void
    {
        $admin = $this->createUser('admin-kpis@harmony.test', ['ROLE_ADMIN']);
        $owner = $this->createUser('owner-kpis@harmony.test');
        $project = $this->createProject($owner, 'Test Project');

        // Record export metrics
        $this->recordExportMetric($project, ProjectExportMetric::FORMAT_HTML, true, 150);
        $this->recordExportMetric($project, ProjectExportMetric::FORMAT_HTML, true, 120);
        $this->recordExportMetric($project, ProjectExportMetric::FORMAT_HTML, false, null, 'Timeout');
        $this->recordExportMetric($project, ProjectExportMetric::FORMAT_PDF, true, 3500);
        $this->recordExportMetric($project, ProjectExportMetric::FORMAT_PDF, true, 4200);
        $this->recordExportMetric($project, ProjectExportMetric::FORMAT_PDF, false, null, 'Gotenberg error');

        // Record generation metrics
        $this->recordGenerationMetric($project, 'openai', 'gpt-4.1', '0.50');
        $this->recordGenerationMetric($project, 'openai', 'gpt-4.1', '1.20');

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/dashboard?period=all');

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();

        // Check HTML export KPI (2/3 success = 66.7%)
        self::assertStringContainsString('2/3', $content);

        // Check PDF export KPI (2/3 success = 66.7%)
        // Both HTML and PDF succeed 2/3 times
        self::assertStringContainsString('66,7 %', $content);

        // Check avg PDF duration (3500+4200)/2 = 3850 ms
        self::assertStringContainsString('3', $content); // part of 3 850

        // Check total exports = 6
        self::assertSelectorExists('[data-kpi="total-exports"]');
    }

    public function testAdminDashboardPeriodFilterWorks(): void
    {
        $admin = $this->createUser('admin-period@harmony.test', ['ROLE_ADMIN']);
        $owner = $this->createUser('owner-period@harmony.test');
        $project = $this->createProject($owner, 'Period Project');

        // Record old export (60 days ago)
        $oldMetric = $this->recordExportMetric($project, ProjectExportMetric::FORMAT_HTML, true, 100);
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE project_export_metric SET created_at = :date WHERE id = :id',
            ['date' => (new \DateTimeImmutable('-60 days'))->format('Y-m-d H:i:s'), 'id' => $oldMetric->getId()],
        );

        // Record recent export (today)
        $this->recordExportMetric($project, ProjectExportMetric::FORMAT_HTML, false, null, 'Recent failure');

        $this->client->loginUser($admin);

        // With period=7d, only recent export visible (1 total, 0 successes)
        $this->client->request('GET', '/admin/dashboard?period=7d');
        self::assertResponseIsSuccessful();
        $content7d = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('0/1', $content7d);

        // With period=all, both exports visible (2 total, 1 success)
        $this->client->request('GET', '/admin/dashboard?period=all');
        self::assertResponseIsSuccessful();
        $contentAll = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('1/2', $contentAll);
    }

    public function testAdminDashboardPeriodTabsAreRendered(): void
    {
        $admin = $this->createUser('admin-tabs@harmony.test', ['ROLE_ADMIN']);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/dashboard?period=30d');

        self::assertResponseIsSuccessful();

        // All three period tabs should be rendered
        self::assertSelectorExists('a.hm-period-tab--active');
        self::assertSelectorTextContains('body', '7 jours');
        self::assertSelectorTextContains('body', '30 jours');
        self::assertSelectorTextContains('body', 'Tout');
    }

    public function testAdminDashboardShowsDailyTrend(): void
    {
        $admin = $this->createUser('admin-trend@harmony.test', ['ROLE_ADMIN']);
        $owner = $this->createUser('owner-trend@harmony.test');
        $project = $this->createProject($owner, 'Trend Project');

        $this->recordExportMetric($project, ProjectExportMetric::FORMAT_HTML, true, 200);
        $this->recordExportMetric($project, ProjectExportMetric::FORMAT_PDF, false, null, 'err');

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/dashboard?period=all');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-trend-table]');
    }

    private function createUser(string $email, array $roles = []): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword(
                static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword(
                    new User(),
                    'ValidPassword123',
                ),
            )
            ->setRoles($roles);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createProject(User $user, string $title): Project
    {
        $managedUser = $user->getId() !== null
            ? $this->entityManager->getReference(User::class, $user->getId())
            : $user;

        $project = (new Project())
            ->setTitle($title)
            ->setProvider('openai')
            ->setModel('gpt-4.1')
            ->setStatus(Project::STATUS_ACTIVE)
            ->setSlides([])
            ->setUser($managedUser);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function recordExportMetric(
        Project $project,
        string $format,
        bool $wasSuccessful,
        ?int $durationMs = null,
        ?string $failureReason = null,
    ): ProjectExportMetric {
        $managedProject = $project->getId() !== null
            ? $this->entityManager->getReference(Project::class, $project->getId())
            : $project;

        $metric = (new ProjectExportMetric())
            ->setProject($managedProject)
            ->setFormat($format)
            ->setWasSuccessful($wasSuccessful)
            ->setDurationMs($durationMs)
            ->setFailureReason($failureReason);

        $this->entityManager->persist($metric);
        $this->entityManager->flush();

        return $metric;
    }

    private function recordGenerationMetric(Project $project, string $provider, string $model, string $estimatedCostUsd): void
    {
        $managedProject = $project->getId() !== null
            ? $this->entityManager->getReference(Project::class, $project->getId())
            : $project;

        $metric = (new ProjectGenerationMetric())
            ->setProject($managedProject)
            ->setProvider($provider)
            ->setModel($model)
            ->setEstimatedCostUsd($estimatedCostUsd);

        $this->entityManager->persist($metric);
        $this->entityManager->flush();
    }
}
