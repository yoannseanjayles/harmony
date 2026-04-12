<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectVersionRepository;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * T192 — Functional tests for color and typography customization with persistence verification.
 *
 * Covers:
 *   - T190 — AJAX endpoint POST /projects/{id}/theme/tokens persists valid token overrides
 *   - T191 — Slide renderHash invalidation after token save
 *   - T185 — Colour token acceptance / rejection
 *   - T187 — Typography token acceptance / rejection
 *   - T188 — Density token acceptance / rejection
 *   - Security: CSRF, ownership, archived project guard
 */
final class ProjectThemeCustomizationTest extends FunctionalTestCase
{
    // ── T190 / T185 — Persist colour overrides ───────────────────────────────

    public function testAjaxPatchPersistsValidColorTokens(): void
    {
        $user    = $this->createUser('theme-color@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/projects/'.$project->getId().'/theme/tokens',
            [
                '_token'             => $this->csrfToken($project),
                'tokens' => [
                    '--hm-bg' => '#1a1a2e',
                    '--hm-ink' => '#e2e2ff',
                ],
            ],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();
        self::assertJson((string) $this->client->getResponse()->getContent());

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);

        $reloaded = $this->reloadProject($project->getId());
        self::assertSame('#1a1a2e', $reloaded->getEffectiveThemeConfig()['--hm-bg']);
        self::assertSame('#e2e2ff', $reloaded->getEffectiveThemeConfig()['--hm-ink']);
    }

    public function testAjaxPatchReturnsJsonWithCssBlock(): void
    {
        $user    = $this->createUser('theme-css@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/projects/'.$project->getId().'/theme/tokens',
            [
                '_token'          => $this->csrfToken($project),
                'tokens' => [
                    '--hm-bg' => '#ff0000',
                ],
            ],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('cssBlock', $data);
        self::assertStringContainsString('--hm-bg:#ff0000', $data['cssBlock']);
    }

    // ── T185 — Invalid colour values are rejected ─────────────────────────────

    public function testInvalidColorValuesAreDropped(): void
    {
        $user    = $this->createUser('theme-invalid-color@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $originalConfig = $project->getThemeConfig();

        $this->client->request(
            'POST',
            '/projects/'.$project->getId().'/theme/tokens',
            [
                '_token' => $this->csrfToken($project),
                'tokens' => [
                    '--hm-bg'             => 'red; color:blue',    // injection attempt
                    '--hm-accent-primary' => 'javascript:alert(1)', // injection attempt
                    '--hm-ink'            => '#gggggg',             // invalid hex
                ],
            ],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();

        $reloaded = $this->reloadProject($project->getId());
        // The patch contained no valid values — the theme config must be unchanged
        self::assertSame($originalConfig, $reloaded->getThemeConfig());
    }

    // ── T185 — Unknown token names are rejected ───────────────────────────────

    public function testUnknownTokenNamesAreDropped(): void
    {
        $user    = $this->createUser('theme-unknown-token@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $originalConfig = $project->getThemeConfig();

        $this->client->request(
            'POST',
            '/projects/'.$project->getId().'/theme/tokens',
            [
                '_token' => $this->csrfToken($project),
                'tokens' => [
                    'color'          => '#ff0000',     // no --hm- prefix
                    '--custom-token' => '#ff0000',     // not in allowlist
                    '--hm-unknown-var' => '#ff0000',   // not in allowlist
                ],
            ],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();

        $reloaded = $this->reloadProject($project->getId());
        self::assertSame($originalConfig, $reloaded->getThemeConfig());
        self::assertArrayNotHasKey('color', $reloaded->getThemeConfig());
        self::assertArrayNotHasKey('--custom-token', $reloaded->getThemeConfig());
    }

    // ── T187 — Typography tokens ──────────────────────────────────────────────

    public function testValidFontFamilyTokensPersist(): void
    {
        $user    = $this->createUser('theme-font@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/projects/'.$project->getId().'/theme/tokens',
            [
                '_token'                   => $this->csrfToken($project),
                'tokens' => [
                    '--hm-font-body' => 'Georgia, "Times New Roman", serif',
                    '--hm-font-title' => '"Inter", "Segoe UI", system-ui, sans-serif',
                ],
            ],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();

        $reloaded = $this->reloadProject($project->getId());
        self::assertSame('Georgia, "Times New Roman", serif', $reloaded->getEffectiveThemeConfig()['--hm-font-body']);
        self::assertSame('"Inter", "Segoe UI", system-ui, sans-serif', $reloaded->getEffectiveThemeConfig()['--hm-font-title']);
    }

    public function testInvalidFontFamilyIsDropped(): void
    {
        $user    = $this->createUser('theme-font-invalid@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $originalConfig = $project->getThemeConfig();

        $this->client->request(
            'POST',
            '/projects/'.$project->getId().'/theme/tokens',
            [
                '_token'                  => $this->csrfToken($project),
                'tokens' => [
                    '--hm-font-body' => 'Comic Sans, cursive; color:red',
                ],
            ],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();

        $reloaded = $this->reloadProject($project->getId());
        self::assertSame($originalConfig, $reloaded->getThemeConfig());
    }

    public function testValidFontWeightTokenPersists(): void
    {
        $user    = $this->createUser('theme-weight@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/projects/'.$project->getId().'/theme/tokens',
            [
                '_token'                          => $this->csrfToken($project),
                'tokens' => [
                    '--hm-font-weight-bold' => '600',
                    '--hm-font-weight-normal' => '400',
                ],
            ],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();

        $reloaded = $this->reloadProject($project->getId());
        self::assertSame('600', $reloaded->getEffectiveThemeConfig()['--hm-font-weight-bold']);
        self::assertSame('400', $reloaded->getEffectiveThemeConfig()['--hm-font-weight-normal']);
    }

    // ── T188 — Density tokens ─────────────────────────────────────────────────

    public function testValidLetterSpacingTokenPersists(): void
    {
        $user    = $this->createUser('theme-spacing@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/projects/'.$project->getId().'/theme/tokens',
            [
                '_token'                                => $this->csrfToken($project),
                'tokens' => [
                    '--hm-letter-spacing-label' => '0.10em',
                    '--hm-letter-spacing-tight' => '-0.03em',
                ],
            ],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();

        $reloaded = $this->reloadProject($project->getId());
        self::assertSame('0.10em', $reloaded->getEffectiveThemeConfig()['--hm-letter-spacing-label']);
        self::assertSame('-0.03em', $reloaded->getEffectiveThemeConfig()['--hm-letter-spacing-tight']);
    }

    public function testValidFontSizeTitleTokenPersists(): void
    {
        $user    = $this->createUser('theme-size@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/projects/'.$project->getId().'/theme/tokens',
            [
                '_token'                       => $this->csrfToken($project),
                'tokens' => [
                    '--hm-font-size-title' => 'clamp(1.8rem, 3.5vw, 3rem)',
                ],
            ],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();

        $reloaded = $this->reloadProject($project->getId());
        self::assertSame('clamp(1.8rem, 3.5vw, 3rem)', $reloaded->getEffectiveThemeConfig()['--hm-font-size-title']);
    }

    public function testInvalidFontSizeTitleIsDropped(): void
    {
        $user    = $this->createUser('theme-size-bad@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $originalConfig = $project->getThemeConfig();

        $this->client->request(
            'POST',
            '/projects/'.$project->getId().'/theme/tokens',
            [
                '_token'                       => $this->csrfToken($project),
                'tokens' => [
                    '--hm-font-size-title' => '999rem; color:red',
                ],
            ],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();

        $reloaded = $this->reloadProject($project->getId());
        self::assertSame($originalConfig, $reloaded->getThemeConfig());
    }

    // ── T191 — renderHash invalidation ───────────────────────────────────────

    public function testPatchInvalidatesSlideRenderHash(): void
    {
        $user    = $this->createUser('theme-hash@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        // Pre-populate a slide with a renderHash to verify it gets invalidated
        $slide = new \App\Entity\Slide();
        $slide->setProject($project)
              ->setType(\App\Entity\Slide::TYPE_TITLE)
              ->setRenderHash('aabbcc1122334455aabbcc1122334455aabbcc1122334455aabbcc1122334455')
              ->setHtmlCache('<div>cached</div>');
        $this->entityManager->persist($slide);
        $this->entityManager->flush();

        $this->client->request(
            'POST',
            '/projects/'.$project->getId().'/theme/tokens',
            [
                '_token'          => $this->csrfToken($project),
                'tokens' => [
                    '--hm-bg' => '#ff0000',
                ],
            ],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $refreshedSlide = $this->entityManager->find(\App\Entity\Slide::class, $slide->getId());
        self::assertInstanceOf(\App\Entity\Slide::class, $refreshedSlide);
        self::assertNull($refreshedSlide->getRenderHash(), 'renderHash should be null after token patch');
        self::assertNull($refreshedSlide->getHtmlCache(), 'htmlCache should be null after token patch');
    }

    // ── T191 — Version capture on patch ───────────────────────────────────────

    public function testPatchCapturesNewProjectVersion(): void
    {
        $user    = $this->createUser('theme-version@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $versionCountBefore = static::getContainer()
            ->get(ProjectVersionRepository::class)
            ->countByProject($project);

        $this->client->request(
            'POST',
            '/projects/'.$project->getId().'/theme/tokens',
            [
                '_token'          => $this->csrfToken($project),
                'tokens' => [
                    '--hm-bg' => '#aabbcc',
                ],
            ],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $refreshedProject = $this->entityManager->find(Project::class, $project->getId());
        $versionCountAfter = static::getContainer()
            ->get(ProjectVersionRepository::class)
            ->countByProject($refreshedProject);

        self::assertSame($versionCountBefore + 1, $versionCountAfter);
    }

    // ── Security: CSRF ─────────────────────────────────────────────────────────

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $user    = $this->createUser('theme-csrf@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/projects/'.$project->getId().'/theme/tokens',
            [
                '_token'          => 'invalid-token',
                'tokens' => [
                    '--hm-bg' => '#ff0000',
                ],
            ],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest'],
        );

        self::assertResponseStatusCodeSame(403);
    }

    // ── Security: ownership ───────────────────────────────────────────────────

    public function testAnotherUserCannotPatchTokens(): void
    {
        $owner    = $this->createUser('theme-owner@harmony.test');
        $intruder = $this->createUser('theme-intruder@harmony.test');
        $project  = $this->createProject($owner);

        $this->client->loginUser($intruder);

        $this->client->request(
            'POST',
            '/projects/'.$project->getId().'/theme/tokens',
            ['_token' => 'any', 'tokens' => ['--hm-bg' => '#ff0000']],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest'],
        );

        self::assertResponseStatusCodeSame(404);
    }

    // ── Security: archived project ────────────────────────────────────────────

    public function testArchivedProjectCannotBePatchedViaTokens(): void
    {
        $user    = $this->createUser('theme-archived@harmony.test');
        $project = $this->createProject($user);
        $project->archive();
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/projects/'.$project->getId().'/theme/tokens',
            ['_token' => 'any', 'tokens' => ['--hm-bg' => '#ff0000']],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest'],
        );

        self::assertResponseStatusCodeSame(404);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

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

    private function createProject(User $user): Project
    {
        $project = (new Project())
            ->setTitle('Theme test project')
            ->setProvider('openai')
            ->setModel('gpt-4.1-mini')
            ->setStatus(Project::STATUS_ACTIVE)
            ->setUser($user);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function csrfToken(Project $project): string
    {
        // Get the token from the rendered page (the customization drawer renders the token in data attribute)
        $crawler = $this->client->request('GET', '/projects/'.$project->getId());
        self::assertResponseIsSuccessful();

        $csrfValue = $crawler
            ->filter('[data-theme-customizer]')
            ->attr('data-csrf-token');

        self::assertNotEmpty($csrfValue, 'Theme customizer CSRF token not found in rendered page');

        return (string) $csrfValue;
    }

    private function reloadProject(int $projectId): Project
    {
        $this->entityManager->clear();
        $project = $this->entityManager->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $project);

        return $project;
    }
}
