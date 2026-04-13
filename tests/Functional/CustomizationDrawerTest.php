<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\User;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * T299–T306 (HRM-F37) — Unified customization drawer.
 *
 * Covers:
 *   - T299 — Drawer trigger button is rendered for non-archived projects
 *   - T300 — All four tab buttons are present
 *   - T301 — Theme panels (preset + customizer) are rendered inside the drawer
 *   - T302 — Animations panel is rendered inside the drawer
 *   - T303 — AI panel shows provider, model and cost info
 *   - T304 — Export panel triggers are present
 *   - T305 — Archived project does not render the drawer or the open button
 */
final class CustomizationDrawerTest extends FunctionalTestCase
{
    // ── T299 — Trigger button rendered for active project ─────────────────

    public function testOpenButtonRenderedForActiveProject(): void
    {
        $user    = $this->createUser('drawer-open@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('[data-drawer-open="hm-customization-drawer"]'),
            'Expected one "Personnaliser" button on the show page.',
        );
    }

    // ── T299 — Drawer element is present in the DOM ───────────────────────

    public function testDrawerElementRenderedForActiveProject(): void
    {
        $user    = $this->createUser('drawer-dom@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('[data-customization-drawer]'),
            'Expected the customization drawer element in the DOM.',
        );
    }

    // ── T300 — All four tab buttons are present ───────────────────────────

    public function testAllFourTabsPresent(): void
    {
        $user    = $this->createUser('drawer-tabs@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());

        self::assertResponseIsSuccessful();

        $tabs = $crawler->filter('[data-customization-drawer] [role="tab"]');
        self::assertCount(4, $tabs, 'Expected exactly 4 tab buttons in the customization drawer.');

        $tabTexts = $tabs->each(fn ($node) => trim($node->text()));
        self::assertContains('Thème', $tabTexts);
        self::assertContains('Animations', $tabTexts);
        self::assertContains('IA', $tabTexts);
        self::assertContains('Export', $tabTexts);
    }

    // ── T301 — Theme preset form rendered inside the drawer ───────────────

    public function testThemePanelContainsPresetForm(): void
    {
        $user    = $this->createUser('drawer-theme@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());

        self::assertResponseIsSuccessful();

        $themePanel = $crawler->filter('#hm-panel-theme');
        self::assertCount(1, $themePanel, 'Expected #hm-panel-theme in the DOM.');
        self::assertStringContainsString(
            'preset',
            (string) $themePanel->html(),
            'Expected the preset form inside the theme panel.',
        );
    }

    // ── T301 — Theme customizer rendered inside the drawer ────────────────

    public function testThemePanelContainsCustomizer(): void
    {
        $user    = $this->createUser('drawer-customizer@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());

        self::assertResponseIsSuccessful();

        $themePanel = $crawler->filter('#hm-panel-theme');
        self::assertCount(1, $themePanel);
        self::assertCount(
            1,
            $themePanel->filter('[data-theme-customizer]'),
            'Expected the theme customizer element inside #hm-panel-theme.',
        );
    }

    // ── T302 — Animations panel contains the animation controls ──────────

    public function testAnimationsPanelContainsControls(): void
    {
        $user    = $this->createUser('drawer-anim@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());

        self::assertResponseIsSuccessful();

        $animPanel = $crawler->filter('#hm-panel-animations');
        self::assertCount(1, $animPanel, 'Expected #hm-panel-animations in the DOM.');
        self::assertCount(
            1,
            $animPanel->filter('[data-anim-control]'),
            'Expected the animation control element inside #hm-panel-animations.',
        );
    }

    // ── T303 — AI panel shows provider and model ──────────────────────────

    public function testAiPanelShowsProviderAndModel(): void
    {
        $user    = $this->createUser('drawer-ai@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());

        self::assertResponseIsSuccessful();

        $aiPanel = $crawler->filter('#hm-panel-ai');
        self::assertCount(1, $aiPanel, 'Expected #hm-panel-ai in the DOM.');

        $html = (string) $aiPanel->html();
        self::assertStringContainsString('Fournisseur', $html);
        self::assertStringContainsString('Modèle', $html);
        self::assertStringContainsString('Coût IA estimé', $html);
    }

    // ── T304 — Export panel contains HTML and PDF buttons ─────────────────

    public function testExportPanelContainsButtons(): void
    {
        $user    = $this->createUser('drawer-export@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());

        self::assertResponseIsSuccessful();

        $exportPanel = $crawler->filter('#hm-panel-export');
        self::assertCount(1, $exportPanel, 'Expected #hm-panel-export in the DOM.');
        self::assertCount(
            1,
            $exportPanel->filter('[data-export-btn="html"]'),
            'Expected the HTML export button inside the export panel.',
        );
        self::assertCount(
            1,
            $exportPanel->filter('[data-export-btn="pdf"]'),
            'Expected the PDF export button inside the export panel.',
        );
    }

    // ── T305 — Archived project: drawer and open button absent ───────────

    public function testArchivedProjectHidesDrawer(): void
    {
        $user    = $this->createUser('drawer-archived@harmony.test');
        $project = $this->createProject($user);
        $project->archive();
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('[data-drawer-open="hm-customization-drawer"]'),
            'The open button must not be rendered for archived projects.',
        );
        self::assertCount(
            0,
            $crawler->filter('[data-customization-drawer]'),
            'The customization drawer must not be rendered for archived projects.',
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

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
            ->setTitle('Drawer test project')
            ->setProvider('openai')
            ->setModel('gpt-4.1-mini')
            ->setStatus(Project::STATUS_ACTIVE)
            ->setUser($user);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }
}
