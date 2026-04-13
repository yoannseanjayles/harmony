<?php

namespace App\Tests\Functional;

use App\Entity\MediaAsset;
use App\Entity\Project;
use App\Entity\Slide;
use App\Entity\User;
use App\Repository\MediaAssetRepository;
use App\Repository\SlideRepository;
use App\Tests\FunctionalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * T248 — Functional tests for the media management drawer.
 *
 * Covers:
 *   - T241 — GET /media/project/{projectId}/assets returns the asset list
 *   - T242 — POST /media/{id}/replace replaces the file and updates the mediaRefsJson
 *   - T244 / T245 / T246 — POST /media/slide/{slideId}/overlay persists overlay settings
 *   - Security: unauthenticated and non-owner access is rejected for all new endpoints
 */
final class MediaDrawerTest extends FunctionalTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/harmony_drawer_test_'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }

        $uploadDir = dirname(__DIR__, 2).'/var/uploads_test';
        if (is_dir($uploadDir)) {
            foreach (glob($uploadDir.'/*.{jpg,png,webp,gif,bin}', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        parent::tearDown();
    }

    // ── T241 — GET /media/project/{projectId}/assets ──────────────────────────

    public function testProjectAssetsListReturnsJsonWithAssets(): void
    {
        $user    = $this->createUser('asset-list@harmony.test');
        $project = $this->createProject($user);
        $asset   = $this->uploadAsset($user, $project);

        $this->client->loginUser($user);
        $this->client->request('GET', '/media/project/'.$project->getId().'/assets');

        self::assertResponseIsSuccessful();
        self::assertJson((string) $this->client->getResponse()->getContent());

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('assets', $data);
        self::assertIsArray($data['assets']);
        self::assertCount(1, $data['assets']);

        $item = $data['assets'][0];
        self::assertSame($asset->getId(), $item['id']);
        self::assertSame('test.jpg', $item['filename']);
        self::assertSame('image/jpeg', $item['mimeType']);
        self::assertArrayHasKey('previewUrl', $item);
        self::assertArrayHasKey('slideRefs', $item);
    }

    public function testProjectAssetsListIsEmptyForProjectWithNoAssets(): void
    {
        $user    = $this->createUser('asset-empty@harmony.test');
        $project = $this->createProject($user);

        $this->client->loginUser($user);
        $this->client->request('GET', '/media/project/'.$project->getId().'/assets');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(0, $data['assets']);
    }

    public function testProjectAssetsListRequiresAuthentication(): void
    {
        $user    = $this->createUser('asset-noauth@harmony.test');
        $project = $this->createProject($user);

        $this->client->request('GET', '/media/project/'.$project->getId().'/assets');

        self::assertResponseRedirects('/login');
    }

    public function testProjectAssetsListDeniedForNonOwner(): void
    {
        $owner    = $this->createUser('asset-owner@harmony.test');
        $intruder = $this->createUser('asset-intruder@harmony.test');
        $project  = $this->createProject($owner);

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/media/project/'.$project->getId().'/assets');

        self::assertResponseStatusCodeSame(404);
    }

    // ── T242 — POST /media/{id}/replace ───────────────────────────────────────

    public function testReplaceAssetSwapsFileAndReturnsNewPreviewUrl(): void
    {
        $user    = $this->createUser('replace-ok@harmony.test');
        $project = $this->createProject($user);
        $asset   = $this->uploadAsset($user, $project);

        $originalStorageKey = $asset->getStorageKey();

        $this->client->loginUser($user);

        $replacement = $this->createUploadedFile('replacement.png', 'image/png');
        $this->client->request(
            'POST',
            '/media/'.$asset->getId().'/replace',
            [],
            ['file' => $replacement],
        );

        self::assertResponseIsSuccessful();
        self::assertJson((string) $this->client->getResponse()->getContent());

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('storageKey', $data);
        self::assertArrayHasKey('previewUrl', $data);

        // The asset ID must remain the same (same entity, new file).
        self::assertSame($asset->getId(), $data['id']);

        // The storage key must have changed (new UUID).
        self::assertNotSame($originalStorageKey, $data['storageKey']);
        self::assertStringEndsWith('.png', $data['storageKey']);

        // The previewUrl must reference the new storage key.
        self::assertStringContainsString($data['storageKey'], $data['previewUrl']);

        // Reload entity and verify persistence.
        $this->entityManager->clear();
        /** @var MediaAsset $refreshed */
        $refreshed = static::getContainer()->get(MediaAssetRepository::class)->find($asset->getId());
        self::assertNotNull($refreshed);
        self::assertSame($data['storageKey'], $refreshed->getStorageKey());
        self::assertSame('replacement.png', $refreshed->getFilename());
        self::assertSame('image/png', $refreshed->getMimeType());
        // Variant keys are cleared after replacement.
        self::assertNull($refreshed->getThumbKey());
        self::assertNull($refreshed->getPreviewKey());
        self::assertNull($refreshed->getExportKey());
    }

    public function testReplaceAssetWithInvalidMimeTypeReturns422(): void
    {
        $user    = $this->createUser('replace-badmime@harmony.test');
        $project = $this->createProject($user);
        $asset   = $this->uploadAsset($user, $project);

        $this->client->loginUser($user);

        $badFile = $this->createUploadedFile('script.php', 'application/x-php');
        $this->client->request('POST', '/media/'.$asset->getId().'/replace', [], ['file' => $badFile]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testReplaceAssetWithoutFileReturns422(): void
    {
        $user    = $this->createUser('replace-nofile@harmony.test');
        $project = $this->createProject($user);
        $asset   = $this->uploadAsset($user, $project);

        $this->client->loginUser($user);
        $this->client->request('POST', '/media/'.$asset->getId().'/replace');

        self::assertResponseStatusCodeSame(422);
    }

    public function testReplaceAssetRequiresAuthentication(): void
    {
        $user    = $this->createUser('replace-noauth@harmony.test');
        $project = $this->createProject($user);
        $asset   = $this->uploadAsset($user, $project);

        $this->client->restart();
        $replacement = $this->createUploadedFile('replacement.jpg', 'image/jpeg');
        $this->client->request('POST', '/media/'.$asset->getId().'/replace', [], ['file' => $replacement]);

        self::assertResponseRedirects('/login');
    }

    public function testReplaceAssetDeniedForNonOwner(): void
    {
        $owner    = $this->createUser('replace-owner@harmony.test');
        $intruder = $this->createUser('replace-intruder@harmony.test');
        $project  = $this->createProject($owner);
        $asset    = $this->uploadAsset($owner, $project);

        $this->client->loginUser($intruder);

        $replacement = $this->createUploadedFile('hack.jpg', 'image/jpeg');
        $this->client->request('POST', '/media/'.$asset->getId().'/replace', [], ['file' => $replacement]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── T246 — POST /media/slide/{slideId}/overlay ────────────────────────────

    public function testOverlayEndpointPersistsSettingsInSlideContentJson(): void
    {
        $user    = $this->createUser('overlay-ok@harmony.test');
        $project = $this->createProject($user);
        $asset   = $this->uploadAsset($user, $project);

        // Re-fetch project after entityManager->clear() inside uploadAsset.
        $project = $this->entityManager->find(Project::class, $project->getId());
        $slide   = $this->createSlide($project);

        $this->client->loginUser($user);
        $this->client->request(
            'POST',
            '/media/slide/'.$slide->getId().'/overlay',
            [
                'asset_id'      => $asset->getId(),
                'color'         => '#ff0000',
                'color_opacity' => '50',
                'img_opacity'   => '80',
            ],
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('success', $data);
        self::assertTrue($data['success']);

        // Reload the slide and verify the contentJson was updated (T246).
        $this->entityManager->clear();
        /** @var Slide $refreshed */
        $refreshed = static::getContainer()->get(SlideRepository::class)->find($slide->getId());
        self::assertNotNull($refreshed);

        $content = $refreshed->getContent();
        self::assertArrayHasKey('_mediaOverlay', $content);

        $overlay = $content['_mediaOverlay'];
        self::assertSame($asset->getId(), $overlay['assetId']);
        self::assertSame('#ff0000', $overlay['color']);
        self::assertSame(50, $overlay['colorOpacity']);
        self::assertSame(80, $overlay['imgOpacity']);
    }

    public function testOverlayEndpointClampsOpacityValues(): void
    {
        $user    = $this->createUser('overlay-clamp@harmony.test');
        $project = $this->createProject($user);
        $slide   = $this->createSlide($project);

        $this->client->loginUser($user);
        $this->client->request(
            'POST',
            '/media/slide/'.$slide->getId().'/overlay',
            [
                'asset_id'      => 0,
                'color'         => '#000000',
                'color_opacity' => '999',    // over max → clamped to 100
                'img_opacity'   => '-50',    // under min → clamped to 0
            ],
        );

        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        /** @var Slide $refreshed */
        $refreshed = static::getContainer()->get(SlideRepository::class)->find($slide->getId());
        $overlay   = $refreshed->getContent()['_mediaOverlay'];

        self::assertSame(100, $overlay['colorOpacity']);
        self::assertSame(0, $overlay['imgOpacity']);
    }

    public function testOverlayEndpointInvalidatesSlideRenderCache(): void
    {
        $user    = $this->createUser('overlay-cache@harmony.test');
        $project = $this->createProject($user);
        $slide   = $this->createSlide($project);

        // Give the slide an explicit renderHash to confirm it gets cleared.
        $slide->setRenderHash('original_hash_value');
        $this->entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request(
            'POST',
            '/media/slide/'.$slide->getId().'/overlay',
            ['asset_id' => 0, 'color' => '', 'color_opacity' => '0', 'img_opacity' => '100'],
        );

        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        /** @var Slide $refreshed */
        $refreshed = static::getContainer()->get(SlideRepository::class)->find($slide->getId());
        // setContent() calls invalidateRenderCache(), so renderHash must be null.
        self::assertNull($refreshed->getRenderHash());
    }

    public function testOverlayEndpointRequiresAuthentication(): void
    {
        $user    = $this->createUser('overlay-noauth@harmony.test');
        $project = $this->createProject($user);
        $slide   = $this->createSlide($project);

        $this->client->restart();
        $this->client->request('POST', '/media/slide/'.$slide->getId().'/overlay');

        self::assertResponseRedirects('/login');
    }

    public function testOverlayEndpointDeniedForNonOwner(): void
    {
        $owner    = $this->createUser('overlay-owner@harmony.test');
        $intruder = $this->createUser('overlay-intruder@harmony.test');
        $project  = $this->createProject($owner);
        $slide    = $this->createSlide($project);

        $this->client->loginUser($intruder);
        $this->client->request(
            'POST',
            '/media/slide/'.$slide->getId().'/overlay',
            ['asset_id' => 1, 'color' => '#000', 'color_opacity' => '0', 'img_opacity' => '100'],
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testOverlayEndpointReturns404ForNonExistentSlide(): void
    {
        $user = $this->createUser('overlay-missing@harmony.test');
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/media/slide/999999/overlay',
            ['asset_id' => 1, 'color' => '#000', 'color_opacity' => '0', 'img_opacity' => '100'],
        );

        self::assertResponseStatusCodeSame(404);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

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
            ->setTitle('Media Drawer Test Project')
            ->setProvider('openai')
            ->setModel('gpt-4.1-mini')
            ->setStatus(Project::STATUS_DRAFT)
            ->setUser($user);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function createSlide(Project $project): Slide
    {
        $slide = (new Slide())
            ->setProject($project)
            ->setType(Slide::TYPE_IMAGE)
            ->setContent(['imageUrl' => 'media:1'])
            ->setPosition(1);

        $this->entityManager->persist($slide);
        $this->entityManager->flush();

        return $slide;
    }

    /**
     * Upload a test JPEG and return the persisted MediaAsset entity.
     */
    private function uploadAsset(User $user, Project $project): MediaAsset
    {
        $this->client->loginUser($user);

        $file = $this->createUploadedFile('test.jpg', 'image/jpeg');

        $this->client->request(
            'POST',
            '/media/upload',
            ['project_id' => $project->getId()],
            ['file' => $file],
        );

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->entityManager->clear();

        /** @var MediaAsset $asset */
        $asset = static::getContainer()->get(MediaAssetRepository::class)->find($data['id']);
        self::assertInstanceOf(MediaAsset::class, $asset);

        return $asset;
    }

    private function createUploadedFile(string $originalName, string $mimeType, int $size = 1024): UploadedFile
    {
        $path = $this->tmpDir.'/'.$originalName;
        file_put_contents($path, str_repeat('A', $size));

        return new UploadedFile(
            path: $path,
            originalName: $originalName,
            mimeType: $mimeType,
            error: \UPLOAD_ERR_OK,
            test: true,
        );
    }
}
