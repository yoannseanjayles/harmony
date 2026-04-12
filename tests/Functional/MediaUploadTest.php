<?php

namespace App\Tests\Functional;

use App\Entity\MediaAsset;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\MediaAssetRepository;
use App\Tests\FunctionalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * T216 — Functional tests for media upload: valid files and rejected files.
 *
 * Covers:
 *   - T210 — POST /media/upload accepts multipart/form-data
 *   - T211 — MIME type whitelist validation (image/jpeg, image/png, image/webp, image/gif)
 *   - T212 — File size validation (max 10 MiB by default)
 *   - T214 — Uploaded file is renamed with a UUID
 *   - T215 — Response JSON includes id and previewUrl
 *   - Security: unauthenticated access is rejected; non-owner cannot upload to another project
 */
final class MediaUploadTest extends FunctionalTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/harmony_media_test_'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Clean up any uploaded test files
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }

        // Remove any files written to the test upload directory during tests
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

    // ── T210 / T215 — Successful upload returns 201 with JSON ────────────────

    public function testValidJpegUploadReturns201WithIdAndPreviewUrl(): void
    {
        $user    = $this->createUser('media-upload@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $file = $this->createUploadedFile('test.jpg', 'image/jpeg');

        $this->client->request(
            'POST',
            '/media/upload',
            ['project_id' => $project->getId()],
            ['file' => $file],
        );

        self::assertResponseStatusCodeSame(201);
        self::assertJson((string) $this->client->getResponse()->getContent());

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('storageKey', $data);
        self::assertArrayHasKey('previewUrl', $data);
        self::assertIsInt($data['id']);
        self::assertStringStartsWith('/uploads/media/', $data['previewUrl']);
        self::assertStringEndsWith('.jpg', $data['storageKey']);

        // T214 — storage key must be UUID-based (not the original filename)
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.jpg$/',
            $data['storageKey'],
        );

        // Verify entity was persisted
        $this->entityManager->clear();
        $asset = static::getContainer()->get(MediaAssetRepository::class)->find($data['id']);
        self::assertInstanceOf(MediaAsset::class, $asset);
        self::assertSame('test.jpg', $asset->getFilename());
        self::assertSame('image/jpeg', $asset->getMimeType());
        self::assertSame($project->getId(), $asset->getProject()?->getId());
    }

    public function testValidPngUploadReturns201(): void
    {
        $user    = $this->createUser('media-png@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $file = $this->createUploadedFile('photo.png', 'image/png');

        $this->client->request('POST', '/media/upload', ['project_id' => $project->getId()], ['file' => $file]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringEndsWith('.png', $data['storageKey']);
    }

    public function testValidWebpUploadReturns201(): void
    {
        $user    = $this->createUser('media-webp@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $file = $this->createUploadedFile('image.webp', 'image/webp');

        $this->client->request('POST', '/media/upload', ['project_id' => $project->getId()], ['file' => $file]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringEndsWith('.webp', $data['storageKey']);
    }

    public function testValidGifUploadReturns201(): void
    {
        $user    = $this->createUser('media-gif@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $file = $this->createUploadedFile('anim.gif', 'image/gif');

        $this->client->request('POST', '/media/upload', ['project_id' => $project->getId()], ['file' => $file]);

        self::assertResponseStatusCodeSame(201);
    }

    // ── T211 — Rejected: invalid MIME type ───────────────────────────────────

    public function testUploadWithInvalidMimeTypeReturns422(): void
    {
        $user    = $this->createUser('media-badmime@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $file = $this->createUploadedFile('script.php', 'application/x-php');

        $this->client->request('POST', '/media/upload', ['project_id' => $project->getId()], ['file' => $file]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsStringIgnoringCase('mime', strtolower($data['error']));
    }

    public function testUploadPdfReturns422(): void
    {
        $user    = $this->createUser('media-pdf@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $file = $this->createUploadedFile('document.pdf', 'application/pdf');

        $this->client->request('POST', '/media/upload', ['project_id' => $project->getId()], ['file' => $file]);

        self::assertResponseStatusCodeSame(422);
    }

    // ── T212 — Rejected: file too large ──────────────────────────────────────

    public function testUploadExceedingMaxSizeReturns422(): void
    {
        $user    = $this->createUser('media-toolarge@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        // T216 — Test limit in test env is 5120 bytes (5 KiB); use 6 KiB to trigger validation.
        // This stays well under PHP's upload_max_filesize so the service limit is reached first.
        $file = $this->createUploadedFile('big.jpg', 'image/jpeg', 6144);

        $this->client->request('POST', '/media/upload', ['project_id' => $project->getId()], ['file' => $file]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('size', strtolower($data['error']));
    }

    // ── Security — unauthenticated and non-owner ──────────────────────────────

    public function testUploadRequiresAuthentication(): void
    {
        $this->client->request('POST', '/media/upload', [], []);
        self::assertResponseRedirects('/login');
    }

    public function testNonOwnerCannotUploadToAnotherUsersProject(): void
    {
        $owner    = $this->createUser('media-owner@harmony.test');
        $intruder = $this->createUser('media-intruder@harmony.test');
        $project  = $this->createProject($owner);

        $this->client->loginUser($intruder);

        $file = $this->createUploadedFile('photo.jpg', 'image/jpeg');
        $this->client->request('POST', '/media/upload', ['project_id' => $project->getId()], ['file' => $file]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testUploadWithoutFileSendsUnprocessableEntity(): void
    {
        $user    = $this->createUser('media-nofile@harmony.test');
        $project = $this->createProject($user);
        $this->client->loginUser($user);

        $this->client->request('POST', '/media/upload', ['project_id' => $project->getId()]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testUploadToNonExistentProjectReturns404(): void
    {
        $user = $this->createUser('media-badproject@harmony.test');
        $this->client->loginUser($user);

        $file = $this->createUploadedFile('test.jpg', 'image/jpeg');
        $this->client->request('POST', '/media/upload', ['project_id' => 99999], ['file' => $file]);

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
            ->setTitle('Test Media Project')
            ->setProvider('openai')
            ->setModel('gpt-4.1-mini')
            ->setStatus(Project::STATUS_DRAFT)
            ->setUser($user);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    /**
     * Create a temporary UploadedFile with synthetic content of the given MIME type.
     */
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
