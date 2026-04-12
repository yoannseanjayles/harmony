<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\Slide;
use App\Entity\User;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * T257 — Functional tests for the HTML single-file export.
 *
 * Covers:
 *   - T249 / T255 — GET /export/{id}/html returns a 200 response with Content-Disposition: attachment
 *   - T250 — All slides appear in the exported HTML in position order
 *   - T251 — Project theme CSS tokens are injected in the exported file
 *   - T254 — Standalone template includes keyboard-navigation JavaScript
 *   - T256 — The exported HTML is self-contained (html/head/body present, no external CSS links)
 *   - Security: unauthenticated access redirects to /login; non-owner gets 404
 */
final class ExportHtmlTest extends FunctionalTestCase
{
    // ── T255 — Successful download response ──────────────────────────────────

    public function testExportHtmlReturns200WithAttachmentHeader(): void
    {
        $user    = $this->createUser('export-basic@harmony.test');
        $project = $this->createProjectWithSlides($user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/export/'.$project->getId().'/html');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);

        $contentDisposition = $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertNotNull($contentDisposition);
        self::assertStringContainsString('attachment', $contentDisposition);
        self::assertStringContainsString('.html', $contentDisposition);

        $contentType = $this->client->getResponse()->headers->get('Content-Type');
        self::assertNotNull($contentType);
        self::assertStringContainsString('text/html', $contentType);
    }

    // ── T256 — Self-contained HTML structure ─────────────────────────────────

    public function testExportedHtmlIsWellFormed(): void
    {
        $user    = $this->createUser('export-wellformed@harmony.test');
        $project = $this->createProjectWithSlides($user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/export/'.$project->getId().'/html');

        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<html', $html);
        self::assertStringContainsString('<head>', $html);
        self::assertStringContainsString('<body>', $html);
        self::assertStringContainsString('</body>', $html);
        self::assertStringContainsString('</html>', $html);
    }

    // ── T250 — Slides rendered in position order ──────────────────────────────

    public function testExportedHtmlContainsAllSlidesInOrder(): void
    {
        $user    = $this->createUser('export-slides-order@harmony.test');
        $project = $this->createProjectWithNamedSlides($user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/export/'.$project->getId().'/html');
        $html = (string) $this->client->getResponse()->getContent();

        // All slide titles must appear in the HTML
        self::assertStringContainsString('First Slide', $html);
        self::assertStringContainsString('Second Slide', $html);
        self::assertStringContainsString('Third Slide', $html);

        // They must appear in position order
        $pos1 = strpos($html, 'First Slide');
        $pos2 = strpos($html, 'Second Slide');
        $pos3 = strpos($html, 'Third Slide');
        self::assertNotFalse($pos1);
        self::assertNotFalse($pos2);
        self::assertNotFalse($pos3);
        self::assertLessThan($pos2, $pos1, 'First slide should appear before second');
        self::assertLessThan($pos3, $pos2, 'Second slide should appear before third');
    }

    // ── T251 — Theme CSS tokens injected ─────────────────────────────────────

    public function testExportedHtmlContainsThemeCssTokens(): void
    {
        $user    = $this->createUser('export-theme@harmony.test');
        $project = $this->createProjectWithSlides($user);

        // Set a distinctive theme token so we can assert it appears in the export
        $project->setThemeConfig(['--hm-bg' => '#123456']);
        $this->entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/export/'.$project->getId().'/html');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('--hm-bg', $html);
        self::assertStringContainsString('#123456', $html);
    }

    // ── T254 — Keyboard navigation script ────────────────────────────────────

    public function testExportedHtmlContainsNavigationScript(): void
    {
        $user    = $this->createUser('export-nav@harmony.test');
        $project = $this->createProjectWithSlides($user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/export/'.$project->getId().'/html');
        $html = (string) $this->client->getResponse()->getContent();

        // T254 — navigation JavaScript must be present
        self::assertStringContainsString('<script>', $html);
        self::assertStringContainsString('ArrowLeft', $html);
        self::assertStringContainsString('ArrowRight', $html);
    }

    // ── T256 — Project title in exported file ────────────────────────────────

    public function testExportedHtmlContainsProjectTitle(): void
    {
        $user    = $this->createUser('export-title@harmony.test');
        $project = $this->createProjectWithSlides($user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/export/'.$project->getId().'/html');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString($project->getTitle(), $html);
    }

    // ── T256 — harmony.css is embedded, no external link rel=stylesheet ───────

    public function testExportedHtmlHasNoExternalStylesheetLinks(): void
    {
        $user    = $this->createUser('export-offline@harmony.test');
        $project = $this->createProjectWithSlides($user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/export/'.$project->getId().'/html');
        $html = (string) $this->client->getResponse()->getContent();

        // No external stylesheet link tags should exist (the CSS is embedded)
        self::assertStringNotContainsString('<link rel="stylesheet"', $html);
        // The harmony CSS must be embedded as an inline <style> block
        self::assertStringContainsString('--hm-bg', $html); // harmony.css root token
        self::assertStringContainsString('@keyframes', $html); // harmony.css animation keyframes
    }

    // ── T255 — Empty project (no slides) ─────────────────────────────────────

    public function testExportHtmlWithNoSlidesReturns200(): void
    {
        $user    = $this->createUser('export-empty@harmony.test');
        $project = $this->createProject($user, 'Empty Project');
        $this->client->loginUser($user);

        $this->client->request('GET', '/export/'.$project->getId().'/html');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<!DOCTYPE html>', $html);
    }

    // ── T255 — Filename from project title ───────────────────────────────────

    public function testExportFilenameIsDerivedFromProjectTitle(): void
    {
        $user    = $this->createUser('export-filename@harmony.test');
        $project = $this->createProject($user, 'My Great Project');
        $this->client->loginUser($user);

        $this->client->request('GET', '/export/'.$project->getId().'/html');

        $contentDisposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringContainsString('my-great-project.html', $contentDisposition);
    }

    // ── Security — authentication required ───────────────────────────────────

    public function testExportHtmlRequiresAuthentication(): void
    {
        $user    = $this->createUser('export-auth@harmony.test');
        $project = $this->createProject($user, 'Auth test');

        $this->client->request('GET', '/export/'.$project->getId().'/html');

        self::assertResponseRedirects('/login');
    }

    // ── Security — ownership check ────────────────────────────────────────────

    public function testNonOwnerCannotExportProject(): void
    {
        $owner    = $this->createUser('export-owner@harmony.test');
        $intruder = $this->createUser('export-intruder@harmony.test');
        $project  = $this->createProject($owner, 'Owner Project');

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/export/'.$project->getId().'/html');

        self::assertResponseStatusCodeSame(404);
    }

    // ── Security — deleted project ────────────────────────────────────────────

    public function testDeletedProjectCannotBeExported(): void
    {
        $user    = $this->createUser('export-deleted@harmony.test');
        $project = $this->createProject($user, 'Deleted Project');
        $project->markDeleted();
        $this->entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/export/'.$project->getId().'/html');

        self::assertResponseStatusCodeSame(404);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    private function createProject(User $user, string $title = 'Export Test Project'): Project
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

    /**
     * Create a project with two title slides.
     */
    private function createProjectWithSlides(User $user): Project
    {
        $project = $this->createProject($user);

        $slide1 = (new Slide())
            ->setProject($project)
            ->setType(Slide::TYPE_TITLE)
            ->setPosition(1)
            ->setContentJson((string) json_encode(['title' => 'Slide One', 'subtitle' => 'Subtitle one']));

        $slide2 = (new Slide())
            ->setProject($project)
            ->setType(Slide::TYPE_CONTENT)
            ->setPosition(2)
            ->setContentJson((string) json_encode(['title' => 'Slide Two', 'body' => 'Body text']));

        $this->entityManager->persist($slide1);
        $this->entityManager->persist($slide2);
        $this->entityManager->flush();

        return $project;
    }

    /**
     * Create a project with three slides having distinct ordered titles.
     */
    private function createProjectWithNamedSlides(User $user): Project
    {
        $project = $this->createProject($user, 'Ordered Slides Project');

        foreach ([['First Slide', 1], ['Second Slide', 2], ['Third Slide', 3]] as [$title, $pos]) {
            $slide = (new Slide())
                ->setProject($project)
                ->setType(Slide::TYPE_TITLE)
                ->setPosition($pos)
                ->setContentJson((string) json_encode(['title' => $title]));
            $this->entityManager->persist($slide);
        }
        $this->entityManager->flush();

        return $project;
    }
}
