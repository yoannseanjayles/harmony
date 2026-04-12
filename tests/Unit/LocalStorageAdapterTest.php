<?php

namespace App\Tests\Unit;

use App\Storage\LocalStorageAdapter;
use PHPUnit\Framework\TestCase;

/**
 * T222 — Unit tests for LocalStorageAdapter using a temporary filesystem.
 *
 * Covers:
 *   - put()          : file is written to the upload directory
 *   - get()          : file contents are returned correctly
 *   - delete()       : file is removed (T221); absent key is silently ignored
 *   - getSignedUrl() : returns a plain public URL (no signing needed locally)
 */
final class LocalStorageAdapterTest extends TestCase
{
    private string $tmpDir;
    private LocalStorageAdapter $adapter;

    protected function setUp(): void
    {
        $this->tmpDir  = sys_get_temp_dir().'/harmony_local_adapter_'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);

        $this->adapter = new LocalStorageAdapter(
            uploadDirectory: $this->tmpDir,
            publicBasePath: '/uploads/media',
        );
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
    }

    // ── put() ────────────────────────────────────────────────────────────────

    public function testPutMovesFileToUploadDirectory(): void
    {
        $sourcePath = $this->createTempFile('hello world');
        $storageKey = 'abc123.jpg';

        $this->adapter->put($storageKey, $sourcePath, 'image/jpeg');

        self::assertFileExists($this->tmpDir.'/'.$storageKey);
        self::assertSame('hello world', file_get_contents($this->tmpDir.'/'.$storageKey));
    }

    public function testPutCreatesUploadDirectoryIfMissing(): void
    {
        $nestedDir = $this->tmpDir.'/nested/'.bin2hex(random_bytes(4));
        $adapter   = new LocalStorageAdapter($nestedDir, '/uploads/media');

        $sourcePath = $this->createTempFile('data');
        $adapter->put('file.png', $sourcePath, 'image/png');

        self::assertFileExists($nestedDir.'/file.png');

        // Cleanup nested dir
        unlink($nestedDir.'/file.png');
        rmdir($nestedDir);
        rmdir(dirname($nestedDir));
    }

    public function testPutPreservesFileContents(): void
    {
        $content    = str_repeat('X', 4096);
        $sourcePath = $this->createTempFile($content);

        $this->adapter->put('large.png', $sourcePath, 'image/png');

        self::assertSame($content, file_get_contents($this->tmpDir.'/large.png'));
    }

    // ── get() ────────────────────────────────────────────────────────────────

    public function testGetReturnsStoredFileContents(): void
    {
        $storageKey = 'stored.jpg';
        file_put_contents($this->tmpDir.'/'.$storageKey, 'stored content');

        $result = $this->adapter->get($storageKey);

        self::assertSame('stored content', $result);
    }

    public function testGetThrowsForNonExistentKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->adapter->get('does-not-exist.jpg');
    }

    // ── delete() — T221 ──────────────────────────────────────────────────────

    public function testDeleteRemovesPhysicalFile(): void
    {
        $storageKey = 'to-delete.jpg';
        file_put_contents($this->tmpDir.'/'.$storageKey, 'temporary');

        $this->adapter->delete($storageKey);

        self::assertFileDoesNotExist($this->tmpDir.'/'.$storageKey);
    }

    public function testDeleteIsIdempotentForMissingKey(): void
    {
        // Must not throw — deleting a non-existent key is acceptable.
        $this->adapter->delete('never-existed.jpg');
        $this->addToAssertionCount(1); // confirm we reached this line
    }

    // ── getSignedUrl() ───────────────────────────────────────────────────────

    public function testGetSignedUrlReturnsPublicPath(): void
    {
        $url = $this->adapter->getSignedUrl('abc123.jpg');

        self::assertSame('/uploads/media/abc123.jpg', $url);
    }

    public function testGetSignedUrlStripsTrailingSlashFromBasePath(): void
    {
        $adapter = new LocalStorageAdapter($this->tmpDir, '/uploads/media/');

        self::assertSame('/uploads/media/file.png', $adapter->getSignedUrl('file.png'));
    }

    public function testGetSignedUrlIgnoresExpiresInParameter(): void
    {
        $urlShort = $this->adapter->getSignedUrl('file.jpg', 60);
        $urlLong  = $this->adapter->getSignedUrl('file.jpg', 86400);

        // Local URLs are not time-limited — both must be identical.
        self::assertSame($urlShort, $urlLong);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function createTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'harmony_local_test_');
        file_put_contents((string) $path, $content);

        return (string) $path;
    }
}
