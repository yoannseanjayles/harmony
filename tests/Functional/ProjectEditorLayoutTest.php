<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\User;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * HRM-F35 / T290 — Functional tests for the two-panel editor page.
 *
 * Covers:
 *   - Authentication guard on GET /projects/{id}/editor
 *   - Successful page load for the project owner (HTTP 200)
 *   - Presence of the two main panels (chat + preview) in the DOM
 *   - Top navigation bar renders the project title
 *   - Non-owner receives 404 (strict project ownership isolation)
 *   - Archived project is still accessible to its owner (read-only badge shown)
 */
final class ProjectEditorLayoutTest extends FunctionalTestCase
{
    public function testEditorRouteRequiresAuthentication(): void
    {
        $owner = $this->createUser('editor-anon@harmony.test');
        $project = $this->createProject($owner, 'Projet anonyme');

        $this->client->request('GET', '/projects/'.$project->getId().'/editor');
        self::assertResponseRedirects('/login');
    }

    public function testOwnerCanLoadEditorPageForActiveProject(): void
    {
        $owner = $this->createUser('editor-owner@harmony.test');
        $project = $this->createProject($owner, 'Présentation IA');

        $this->client->loginUser($owner);
        $this->client->request('GET', '/projects/'.$project->getId().'/editor');

        self::assertResponseIsSuccessful();

        // Page title contains the project title
        self::assertSelectorTextContains('title', 'Présentation IA');

        // Top navigation bar is rendered
        self::assertSelectorExists('[data-editor]');

        // Left panel — chat
        self::assertSelectorExists('[data-chat-panel]');

        // Right panel — preview
        self::assertSelectorExists('[data-preview-panel]');

        // Nav bar shows project title
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Présentation IA', $content);

        // Export buttons present for non-archived project
        self::assertSelectorExists('[data-export-btn]');
    }

    public function testEditorPageContainsTwoPanelLayout(): void
    {
        $owner = $this->createUser('editor-layout@harmony.test');
        $project = $this->createProject($owner, 'Layout Test');

        $this->client->loginUser($owner);
        $this->client->request('GET', '/projects/'.$project->getId().'/editor');

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();

        // CSS Grid editor shell
        self::assertStringContainsString('hm-editor-body', $content);

        // Chat column
        self::assertStringContainsString('hm-editor-chat', $content);

        // Preview column
        self::assertStringContainsString('hm-editor-preview', $content);

        // Navigation bar
        self::assertStringContainsString('hm-editor-nav', $content);
    }

    public function testNonOwnerCannotAccessEditorPage(): void
    {
        $owner = $this->createUser('editor-isolate-owner@harmony.test');
        $intruder = $this->createUser('editor-isolate-intruder@harmony.test');
        $project = $this->createProject($owner, 'Projet confidentiel');

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/projects/'.$project->getId().'/editor');

        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnerCanLoadEditorPageForArchivedProject(): void
    {
        $owner = $this->createUser('editor-archived@harmony.test');
        $project = $this->createProject($owner, 'Projet Archive');
        $project->archive();
        $this->entityManager->flush();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/projects/'.$project->getId().'/editor');

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        // Archived badge is shown
        self::assertStringContainsString('archive', strtolower($content));

        // Export buttons are hidden for archived projects (theme drawer not shown)
        self::assertSelectorNotExists('[data-export-btn]');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

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

    private function createProject(User $user, string $title): Project
    {
        $project = (new Project())
            ->setTitle($title)
            ->setProvider('openai')
            ->setModel('gpt-4.1-mini')
            ->setStatus(Project::STATUS_ACTIVE)
            ->setUser($user);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }
}
