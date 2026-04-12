<?php

namespace App\Tests\Unit;

use App\Storage\S3ClientInterface;
use App\Storage\S3StorageAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * T223 — Unit tests for S3StorageAdapter with a mocked S3 client.
 *
 * The real NativeS3Client is never instantiated here — all network interactions
 * are replaced by a PHPUnit mock of S3ClientInterface.
 *
 * Covers:
 *   - put()          : delegates to S3ClientInterface::upload()
 *   - get()          : delegates to S3ClientInterface::download()
 *   - delete()       : delegates to S3ClientInterface::delete() (T221)
 *   - getSignedUrl() : delegates to S3ClientInterface::presign()
 */
final class S3StorageAdapterTest extends TestCase
{
    private const BUCKET = 'harmony-assets';

    private S3ClientInterface&MockObject $s3Client;
    private S3StorageAdapter $adapter;

    protected function setUp(): void
    {
        $this->s3Client = $this->createMock(S3ClientInterface::class);
        $this->adapter  = new S3StorageAdapter($this->s3Client, self::BUCKET);
    }

    // ── put() ────────────────────────────────────────────────────────────────

    public function testPutDelegatesToS3ClientUpload(): void
    {
        $storageKey = 'abc123.jpg';
        $localPath  = '/tmp/source.jpg';
        $mimeType   = 'image/jpeg';

        $this->s3Client
            ->expects($this->once())
            ->method('upload')
            ->with(self::BUCKET, $storageKey, $localPath, $mimeType);

        $this->adapter->put($storageKey, $localPath, $mimeType);
    }

    public function testPutPassesCorrectBucketToClient(): void
    {
        $this->s3Client
            ->expects($this->once())
            ->method('upload')
            ->with(self::BUCKET, $this->anything(), $this->anything(), $this->anything());

        $this->adapter->put('key.png', '/tmp/file.png', 'image/png');
    }

    public function testPutPropagatesClientException(): void
    {
        $this->s3Client
            ->method('upload')
            ->willThrowException(new \RuntimeException('S3 error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S3 error');

        $this->adapter->put('key.jpg', '/tmp/file.jpg', 'image/jpeg');
    }

    // ── get() ────────────────────────────────────────────────────────────────

    public function testGetDelegatesToS3ClientDownload(): void
    {
        $storageKey = 'image.png';

        $this->s3Client
            ->expects($this->once())
            ->method('download')
            ->with(self::BUCKET, $storageKey)
            ->willReturn('file contents');

        $result = $this->adapter->get($storageKey);

        self::assertSame('file contents', $result);
    }

    public function testGetPropagatesClientException(): void
    {
        $this->s3Client
            ->method('download')
            ->willThrowException(new \RuntimeException('Not found'));

        $this->expectException(\RuntimeException::class);

        $this->adapter->get('missing.jpg');
    }

    // ── delete() — T221 ──────────────────────────────────────────────────────

    public function testDeleteDelegatesToS3ClientDelete(): void
    {
        $storageKey = 'to-delete.webp';

        $this->s3Client
            ->expects($this->once())
            ->method('delete')
            ->with(self::BUCKET, $storageKey);

        $this->adapter->delete($storageKey);
    }

    public function testDeletePassesCorrectBucket(): void
    {
        $this->s3Client
            ->expects($this->once())
            ->method('delete')
            ->with(self::BUCKET, $this->anything());

        $this->adapter->delete('any-key.gif');
    }

    // ── getSignedUrl() ───────────────────────────────────────────────────────

    public function testGetSignedUrlDelegatesToS3ClientPresign(): void
    {
        $storageKey  = 'asset.jpg';
        $expiresIn   = 7200;
        $expectedUrl = 'https://bucket.s3.eu-west-3.amazonaws.com/asset.jpg?X-Amz-Signature=abc';

        $this->s3Client
            ->expects($this->once())
            ->method('presign')
            ->with(self::BUCKET, $storageKey, $expiresIn)
            ->willReturn($expectedUrl);

        $url = $this->adapter->getSignedUrl($storageKey, $expiresIn);

        self::assertSame($expectedUrl, $url);
    }

    public function testGetSignedUrlUsesDefaultExpiryOf3600Seconds(): void
    {
        $this->s3Client
            ->expects($this->once())
            ->method('presign')
            ->with(self::BUCKET, $this->anything(), 3600)
            ->willReturn('https://example.com/signed');

        $this->adapter->getSignedUrl('file.jpg');
    }

    public function testGetSignedUrlPropagatesClientException(): void
    {
        $this->s3Client
            ->method('presign')
            ->willThrowException(new \RuntimeException('Signing failed'));

        $this->expectException(\RuntimeException::class);

        $this->adapter->getSignedUrl('file.jpg');
    }
}
