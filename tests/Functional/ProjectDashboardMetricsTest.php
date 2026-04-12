<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\ProjectExportMetric;
use App\Entity\ProjectGenerationMetric;
use App\Entity\User;
use App\Tests\FunctionalTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProjectDashboardMetricsTest extends FunctionalTestCase
{
    public function testDashboardDisplaysMetricsWithSearchFiltersAndStatusSorting(): void
    {
        $owner = $this->createUser('dashboard-owner@harmony.test');
        $otherUser = $this->createUser('dashboard-other@harmony.test');

        $atlas = $this->createProject(
            $owner,
            'Atlas Launch',
            Project::STATUS_ACTIVE,
            'openai',
            'gpt-4.1',
            3,
            '2026-04-12 09:15:00',
        );
        $boreal = $this->createProject(
            $owner,
            'Boreal Archive',
            Project::STATUS_ACTIVE,
            'anthropic',
            'claude-3-7-sonnet',
            4,
            '2026-04-11 18:30:00',
            archived: true,
        );
        $zephyr = $this->createProject(
            $owner,
            'Zephyr Draft',
            Project::STATUS_DRAFT,
            'openai',
            'gpt-4.1-mini',
            1,
            '2026-04-10 08:45:00',
        );

        $hidden = $this->createProject(
            $otherUser,
            'Hidden Deck',
            Project::STATUS_ACTIVE,
            'openai',
            'gpt-4.1',
            2,
            '2026-04-12 07:00:00',
        );

        $this->recordGenerationMetric($atlas, 'openai', 'gpt-4.1', '1.20');
        $this->recordGenerationMetric($boreal, 'anthropic', 'claude-3-7-sonnet', '2.25');
        $this->recordGenerationMetric($zephyr, 'openai', 'gpt-4.1-mini', '0.55');
        $this->recordGenerationMetric($hidden, 'openai', 'gpt-4.1', '9.99');

        $this->recordExportMetric($atlas, ProjectExportMetric::FORMAT_HTML, true);
        $this->recordExportMetric($atlas, ProjectExportMetric::FORMAT_HTML, true);
        $this->recordExportMetric($atlas, ProjectExportMetric::FORMAT_PDF, true);
        $this->recordExportMetric($atlas, ProjectExportMetric::FORMAT_PDF, false);
        $this->recordExportMetric($boreal, ProjectExportMetric::FORMAT_HTML, true);
        $this->recordExportMetric($boreal, ProjectExportMetric::FORMAT_HTML, false);
        $this->recordExportMetric($boreal, ProjectExportMetric::FORMAT_PDF, true);
        $this->recordExportMetric($zephyr, ProjectExportMetric::FORMAT_HTML, false);
        $this->recordExportMetric($hidden, ProjectExportMetric::FORMAT_HTML, true);

        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/projects/dashboard?scope=all&sort=status&direction=asc');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '4,00 USD');
        self::assertSelectorTextContains('body', '60 %');
        self::assertCardTitles($crawler, [
            'Atlas Launch',
            'Boreal Archive',
            'Zephyr Draft',
        ]);

        $atlasCard = $this->findCardTextByTitle($crawler, 'Atlas Launch');
        self::assertStringContainsString('1,20 USD', $atlasCard);
        self::assertStringContainsString('2/2 | 100 %', $atlasCard);
        self::assertStringContainsString('1/2 | 50 %', $atlasCard);

        $zephyrCard = $this->findCardTextByTitle($crawler, 'Zephyr Draft');
        self::assertStringContainsString('0,55 USD', $zephyrCard);
        self::assertStringContainsString('0/1 | 0 %', $zephyrCard);
        self::assertStringContainsString('Aucun export', $zephyrCard);
        self::assertStringNotContainsString('Hidden Deck', (string) $this->client->getResponse()->getContent());

        $archivedCrawler = $this->client->request('GET', '/projects?scope=archived');
        self::assertResponseIsSuccessful();
        self::assertCardTitles($archivedCrawler, ['Boreal Archive']);

        $searchCrawler = $this->client->request('GET', '/projects?scope=all&search=Zephyr');
        self::assertResponseIsSuccessful();
        self::assertCardTitles($searchCrawler, ['Zephyr Draft']);
        self::assertSelectorTextContains('body', '1 projet(s) trouves.');
    }

    public function testDashboardPaginatesProjectsWhenNeeded(): void
    {
        $user = $this->createUser('dashboard-pagination@harmony.test');

        for ($index = 1; $index <= 7; ++$index) {
            $this->createProject(
                $user,
                sprintf('Projet %02d', $index),
                $index % 2 === 0 ? Project::STATUS_ACTIVE : Project::STATUS_DRAFT,
                'openai',
                'gpt-4.1-mini',
                1,
                sprintf('2026-04-%02d 08:00:00', $index),
            );
        }

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects?scope=all&sort=name&direction=asc&page=2');
        self::assertResponseIsSuccessful();
        self::assertCardTitles($crawler, ['Projet 07']);
        self::assertSelectorTextContains('body', 'Page 2 sur 2');
        self::assertStringNotContainsString('Projet 01', (string) $this->client->getResponse()->getContent());
    }

    private function createUser(string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword(
                static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword(
                    new User(),
                    'ValidPassword123',
                ),
            );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createProject(
        User $user,
        string $title,
        string $status,
        string $provider,
        string $model,
        int $slidesCount,
        string $updatedAt,
        bool $archived = false,
    ): Project {
        $managedUser = $user->getId() !== null
            ? $this->entityManager->getReference(User::class, $user->getId())
            : $user;

        $slides = [];
        for ($slideNumber = 1; $slideNumber <= $slidesCount; ++$slideNumber) {
            $slides[] = [
                'id' => strtolower(str_replace(' ', '-', $title)).'-slide-'.$slideNumber,
                'title' => $title.' '.$slideNumber,
            ];
        }

        $project = (new Project())
            ->setTitle($title)
            ->setProvider($provider)
            ->setModel($model)
            ->setStatus($status)
            ->setSlides($slides)
            ->setUser($managedUser);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        if ($archived) {
            $project->archive();
            $this->entityManager->flush();
        }

        $updatedAtValue = new \DateTimeImmutable($updatedAt);
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE project SET updated_at = :updatedAt WHERE id = :id',
            [
                'updatedAt' => $updatedAtValue->format('Y-m-d H:i:s'),
                'id' => $project->getId(),
            ],
        );
        $this->setDateTimeProperty($project, 'updatedAt', $updatedAtValue);
        $this->entityManager->clear();

        $reloadedProject = $this->entityManager->getRepository(Project::class)->find($project->getId());
        self::assertInstanceOf(Project::class, $reloadedProject);

        return $reloadedProject;
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

    private function recordExportMetric(Project $project, string $format, bool $wasSuccessful): void
    {
        $managedProject = $project->getId() !== null
            ? $this->entityManager->getReference(Project::class, $project->getId())
            : $project;

        $metric = (new ProjectExportMetric())
            ->setProject($managedProject)
            ->setFormat($format)
            ->setWasSuccessful($wasSuccessful);

        $this->entityManager->persist($metric);
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $expectedTitles
     */
    private static function assertCardTitles(Crawler $crawler, array $expectedTitles): void
    {
        $titles = $crawler->filter('[data-project-title]')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );

        self::assertSame($expectedTitles, $titles);
    }

    private function findCardTextByTitle(Crawler $crawler, string $title): string
    {
        $cards = $crawler->filter('[data-project-card]');
        foreach ($cards as $card) {
            $cardCrawler = new Crawler($card);
            $cardTitle = trim((string) $cardCrawler->filter('[data-project-title]')->text());
            if ($cardTitle === $title) {
                return trim($cardCrawler->text());
            }
        }

        self::fail(sprintf('No project card found for "%s".', $title));
    }

    private function setDateTimeProperty(Project $project, string $propertyName, \DateTimeImmutable $value): void
    {
        $property = new \ReflectionProperty(Project::class, $propertyName);
        $property->setAccessible(true);
        $property->setValue($project, $value);
    }
}
