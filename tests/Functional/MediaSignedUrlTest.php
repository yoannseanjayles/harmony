<?php

namespace App\Tests\Functional;

use App\Entity\MediaAsset;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\MediaAssetRepository;
use App\Storage\LocalStorageAdapter;
use App\Tests\FunctionalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * T240 — Functional tests for signed URL generation, delivery and verification.
 *
 * Covers:
 *   - T238 — GET /media/{id}/url returns a fresh signed URL
 *   - T239 — Ownership check: non-owner and unauthenticated callers are rejected
 *   - T233 — LocalStorageAdapter: HMAC signature validation via /media/serve/{key}
 *   - Expiry: a tampered / expired "expires" parameter results in 403
 */
final class MediaSignedUrlTest extends FunctionalTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/harmony_signed_url_test_'.bin2hex(random_bytes(4));
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

    // ── T238 — GET /media/{id}/url ────────────────────────────────────────────

    public function testSignedUrlEndpointReturnsJsonWithSignedUrl(): void
    {
        $user    = $this->createUser('signed-url-owner@harmony.test');
        $project = $this->createProject($user);
        $asset   = $this->uploadAsset($user, $project);

        $this->client->loginUser($user);
        $this->client->request('GET', '/media/'.$asset->getId().'/url');

        self::assertResponseIsSuccessful();
        self::assertJson((string) $this->client->getResponse()->getContent());

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('signedUrl', $data);
        self::assertArrayHasKey('expiresIn', $data);
        self::assertIsInt($data['expiresIn']);
        self::assertGreaterThan(0, $data['expiresIn']);
        self::assertNotEmpty($data['signedUrl']);
    }

    public function testSignedUrlContainsStorageKeyForLocalAdapter(): void
    {
        $user    = $this->createUser('signed-url-localkey@harmony.test');
        $project = $this->createProject($user);
        $asset   = $this->uploadAsset($user, $project);

        $this->client->loginUser($user);
        $this->client->request('GET', '/media/'.$asset->getId().'/url');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        // With the test HMAC secret the URL should be a /media/serve/... path.
        self::assertStringContainsString($asset->getStorageKey(), $data['signedUrl']);
    }

    // ── T239 — Ownership verification ────────────────────────────────────────

    public function testSignedUrlRequiresAuthentication(): void
    {
        $user    = $this->createUser('signed-url-noauth@harmony.test');
        $project = $this->createProject($user);
        $asset   = $this->uploadAsset($user, $project);

        // Restart the client to drop the session cookies (simulate an anonymous visitor).
        $this->client->restart();

        $this->client->request('GET', '/media/'.$asset->getId().'/url');

        self::assertResponseRedirects('/login');
    }

    public function testSignedUrlDeniedForNonOwner(): void
    {
        $owner    = $this->createUser('signed-url-own@harmony.test');
        $intruder = $this->createUser('signed-url-intruder@harmony.test');
        $project  = $this->createProject($owner);
        $asset    = $this->uploadAsset($owner, $project);

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/media/'.$asset->getId().'/url');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSignedUrlReturns404ForNonExistentAsset(): void
    {
        $user = $this->createUser('signed-url-missing@harmony.test');
        $this->client->loginUser($user);

        $this->client->request('GET', '/media/999999/url');

        self::assertResponseStatusCodeSame(404);
    }

    // ── T233 — HMAC-signed URL verification via /media/serve/{storageKey} ────

    public function testLocalSignedUrlVerifiesAndServesFile(): void
    {
        $user    = $this->createUser('serve-ok@harmony.test');
        $project = $this->createProject($user);
        $asset   = $this->uploadAsset($user, $project);

        // Get a fresh signed URL from the endpoint.
        $this->client->loginUser($user);
        $this->client->request('GET', '/media/'.$asset->getId().'/url');

        $data      = json_decode((string) $this->client->getResponse()->getContent(), true);
        $signedUrl = $data['signedUrl'];

        // The serve route should accept the fresh signed URL.
        $this->client->request('GET', $signedUrl);

        self::assertResponseIsSuccessful();
    }

    public function testServeRejectsTamperedExpiry(): void
    {
        $user    = $this->createUser('serve-tamper@harmony.test');
        $project = $this->createProject($user);
        $asset   = $this->uploadAsset($user, $project);

        $this->client->loginUser($user);
        $this->client->request('GET', '/media/'.$asset->getId().'/url');

        $data      = json_decode((string) $this->client->getResponse()->getContent(), true);
        $signedUrl = $data['signedUrl'];

        // Parse the URL and tamper with the expires parameter.
        $parsed = parse_url($signedUrl);
        parse_str((string) ($parsed['query'] ?? ''), $params);
        $params['expires'] = (int) $params['expires'] - 7200; // push into the past
        $tamperedUrl = $parsed['path'].'?'.http_build_query($params);

        $this->client->request('GET', $tamperedUrl);

        self::assertResponseStatusCodeSame(403);
    }

    public function testServeRejectsMissingSig(): void
    {
        $user    = $this->createUser('serve-nosig@harmony.test');
        $project = $this->createProject($user);
        $asset   = $this->uploadAsset($user, $project);

        $storageKey = $asset->getStorageKey();
        $expires    = time() + 3600;

        $this->client->request(
            'GET',
            '/media/serve/'.rawurlencode($storageKey).'?expires='.$expires,
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testServeRejectsWrongSig(): void
    {
        $user    = $this->createUser('serve-badsig@harmony.test');
        $project = $this->createProject($user);
        $asset   = $this->uploadAsset($user, $project);

        $storageKey = $asset->getStorageKey();
        $expires    = time() + 3600;

        $this->client->request(
            'GET',
            '/media/serve/'.rawurlencode($storageKey).'?expires='.$expires.'&sig='.str_repeat('0', 64),
        );

        self::assertResponseStatusCodeSame(403);
    }

    // ── T233 — LocalStorageAdapter unit-level HMAC behaviour ─────────────────

    public function testLocalAdapterGeneratesHmacSignedUrl(): void
    {
        $adapter = new LocalStorageAdapter(
            uploadDirectory: $this->tmpDir,
            publicBasePath: '/uploads/media',
            hmacSecret: 'test-secret',
        );

        $url = $adapter->getSignedUrl('abc.jpg', 3600);

        self::assertStringStartsWith('/media/serve/', $url);
        self::assertStringContainsString('expires=', $url);
        self::assertStringContainsString('sig=', $url);
    }

    public function testLocalAdapterVerifiesValidSignature(): void
    {
        $adapter = new LocalStorageAdapter(
            uploadDirectory: $this->tmpDir,
            publicBasePath: '/uploads/media',
            hmacSecret: 'test-secret',
        );

        $url = $adapter->getSignedUrl('abc.jpg', 3600);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $params);

        self::assertTrue($adapter->verifySignature('abc.jpg', (int) $params['expires'], $params['sig']));
    }

    public function testLocalAdapterRejectsExpiredSignature(): void
    {
        $adapter = new LocalStorageAdapter(
            uploadDirectory: $this->tmpDir,
            publicBasePath: '/uploads/media',
            hmacSecret: 'test-secret',
        );

        // Generate a URL that expired 1 second ago.
        $expires  = time() - 1;
        $sig      = hash_hmac('sha256', 'abc.jpg.'.$expires, 'test-secret');

        self::assertFalse($adapter->verifySignature('abc.jpg', $expires, $sig));
    }

    public function testLocalAdapterRejectsTamperedStorageKey(): void
    {
        $adapter = new LocalStorageAdapter(
            uploadDirectory: $this->tmpDir,
            publicBasePath: '/uploads/media',
            hmacSecret: 'test-secret',
        );

        $url = $adapter->getSignedUrl('original.jpg', 3600);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $params);

        // Use the signature from "original.jpg" but ask for "other.jpg".
        self::assertFalse($adapter->verifySignature('other.jpg', (int) $params['expires'], $params['sig']));
    }

    public function testLocalAdapterWithoutSecretReturnsFallbackUrl(): void
    {
        $adapter = new LocalStorageAdapter(
            uploadDirectory: $this->tmpDir,
            publicBasePath: '/uploads/media',
        );

        self::assertSame('/uploads/media/abc.jpg', $adapter->getSignedUrl('abc.jpg'));
        self::assertFalse($adapter->verifySignature('abc.jpg', time() + 3600, 'any'));
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
            ->setTitle('Signed URL Test Project')
            ->setProvider('openai')
            ->setModel('gpt-4.1-mini')
            ->setStatus(Project::STATUS_DRAFT)
            ->setUser($user);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    /**
     * Upload a test JPEG asset for $user/$project and return the persisted MediaAsset.
     */
    private function uploadAsset(User $user, Project $project): MediaAsset
    {
        $this->client->loginUser($user);

        $path = $this->tmpDir.'/test.jpg';
        file_put_contents($path, str_repeat('A', 512));

        $file = new UploadedFile(
            path: $path,
            originalName: 'test.jpg',
            mimeType: 'image/jpeg',
            error: \UPLOAD_ERR_OK,
            test: true,
        );

        $this->client->request(
            'POST',
            '/media/upload',
            ['project_id' => $project->getId()],
            ['file' => $file],
        );

        $data  = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->entityManager->clear();

        $asset = static::getContainer()->get(MediaAssetRepository::class)->find($data['id']);
        self::assertInstanceOf(MediaAsset::class, $asset);

        return $asset;
    }
}
