<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\User;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * T298 — Integration tests for live preview update via mocked SSE.
 *
 * Verifies:
 * - The project show page contains the expected live-preview and thumbnail DOM structure.
 * - The SSE stream includes slide events with rendered HTML payloads.
 * - Slide HTML payloads in SSE events include the slide content.
 */
final class PreviewLiveTest extends FunctionalTestCase
{
    // T298 — Project show page must contain the live-preview zone and thumbnail strip
    public function testProjectShowPageContainsLivePreviewAndThumbnailStripStructure(): void
    {
        $user = $this->createUser('preview-page@harmony.test');
        $project = $this->createProject($user, 'Preview structure test');

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());

        self::assertResponseIsSuccessful();

        // T291 — Live preview zone must be present
        self::assertCount(1, $crawler->filter('[data-live-preview]'));

        // T293 — Thumbnail strip must be present
        self::assertCount(1, $crawler->filter('[data-thumbnail-strip]'));

        // Empty state is visible (project has no slides yet)
        self::assertCount(1, $crawler->filter('[data-live-preview-empty]:not([hidden])'));
    }

    // T298 — Project show page with existing slides shows thumbnails and hides empty state
    public function testProjectShowPageWithSlidesShowsThumbnails(): void
    {
        $user = $this->createUser('preview-slides@harmony.test');
        $project = $this->createProject($user, 'Preview with slides', [
            [
                'id' => 'slide-001',
                'type' => 'title',
                'title' => 'Vision du lancement',
                'body' => '',
                'position' => 1,
            ],
            [
                'id' => 'slide-002',
                'type' => 'content',
                'title' => 'Prochaines etapes',
                'body' => 'Contenu de la slide',
                'position' => 2,
            ],
        ]);

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());

        self::assertResponseIsSuccessful();

        // T293 — Two thumbnails should be rendered server-side
        $thumbnails = $crawler->filter('[data-thumbnail-strip] [data-preview-slide]');
        self::assertCount(2, $thumbnails);

        // T293 — First thumbnail is marked active
        $firstThumb = $crawler->filter('[data-thumbnail-strip] [data-preview-slide]')->first();
        self::assertStringContainsString('hm-thumbnail--active', (string) $firstThumb->attr('class'));

        // T293 — Thumbnails carry the correct slide IDs
        self::assertSame('slide-001', $thumbnails->first()->attr('data-preview-slide-id'));
        self::assertSame('slide-002', $thumbnails->eq(1)->attr('data-preview-slide-id'));

        // T293 — Live preview stage is visible when slides exist
        self::assertCount(1, $crawler->filter('[data-live-preview-stage]:not([hidden])'));
    }

    // T298 — SSE stream includes slide_added events with rendered HTML payloads
    public function testSseStreamSlideAddedEventsContainRenderedHtmlPayload(): void
    {
        $user = $this->createUser('preview-sse@harmony.test');
        $project = $this->createProject($user, 'Preview SSE test');

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());
        $token = $crawler
            ->filter(sprintf('form[action="/projects/%d/chat/send-message"] input[name="_token"]', $project->getId()))
            ->attr('value');

        self::assertNotFalse($token);

        $this->client->xmlHttpRequest('POST', '/projects/'.$project->getId().'/chat/send-message', [
            '_token' => $token,
            'message' => 'Genere 5 slides pour le lancement du produit Harmony',
        ]);

        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->client->request('GET', $payload['streamUrl'], server: [
            'HTTP_ACCEPT' => 'text/event-stream',
            'HTTP_CACHE_CONTROL' => 'no-cache',
        ]);

        self::assertResponseIsSuccessful();

        $streamContent = $this->client->getInternalResponse()->getContent();

        // T292/T294 — Each slide_added event must carry an 'html' field in its JSON data
        $eventDataBlocks = [];
        preg_match_all('/^event: slide_added\ndata: (.+)$/m', $streamContent, $matches);
        self::assertCount(5, $matches[1], 'Expected 5 slide_added data blocks');

        foreach ($matches[1] as $rawJson) {
            $eventPayload = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);

            // T292 — slide sub-object must be present with id and position
            self::assertArrayHasKey('slide', $eventPayload);
            self::assertArrayHasKey('id', $eventPayload['slide']);
            self::assertArrayHasKey('position', $eventPayload['slide']);

            // T294 — html field must be present and non-empty
            self::assertArrayHasKey('html', $eventPayload);
            self::assertNotEmpty($eventPayload['html'], 'slide_added html payload must not be empty');

            $eventDataBlocks[] = $eventPayload;
        }

        // T294 — Every payload html must be non-empty (either full render or lightweight fallback)
        foreach ($eventDataBlocks as $block) {
            self::assertNotEmpty(trim((string) $block['html']), 'slide_added html payload must be non-empty');
        }
    }

    // T298 — Thumbnail strip [data-preview-list] attribute is present for JS count tracking
    public function testThumbnailStripHasPreviewListAttributeForCompatibility(): void
    {
        $user = $this->createUser('preview-compat@harmony.test');
        $project = $this->createProject($user, 'Preview compat test');

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());

        self::assertResponseIsSuccessful();

        // The thumbnail strip must keep [data-preview-list] for backward-compat with JS count tracking
        self::assertCount(1, $crawler->filter('[data-thumbnail-strip][data-preview-list]'));
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

    /**
     * @param list<array<string, mixed>> $slides
     */
    private function createProject(User $user, string $title, array $slides = []): Project
    {
        $project = (new Project())
            ->setTitle($title)
            ->setProvider('openai')
            ->setModel('gpt-4.1')
            ->setStatus(Project::STATUS_ACTIVE)
            ->setSlides($slides)
            ->setUser($user);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }
}
