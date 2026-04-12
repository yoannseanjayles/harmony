<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();

        $this->databasePath = self::databasePath();
        self::configureTestDatabase($this->databasePath);

        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }

        self::cleanupChatStreams();

        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get('cache.rate_limiter')->clear();

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        if ($metadata !== []) {
            $tool = new SchemaTool($this->entityManager);
            $tool->createSchema($metadata);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->entityManager->close();
        }

        if (static::getContainer()->has('cache.rate_limiter')) {
            static::getContainer()->get('cache.rate_limiter')->clear();
        }

        self::ensureKernelShutdown();
        gc_collect_cycles();

        if (isset($this->databasePath) && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }

        self::cleanupChatStreams();

        unset($this->entityManager, $this->client);

        parent::tearDown();
    }

    private static function databasePath(): string
    {
        return dirname(__DIR__).'/var/harmony_test_'.getmypid().'_'.bin2hex(random_bytes(4)).'.db';
    }

    private static function configureTestDatabase(string $databasePath): void
    {
        $databaseUrl = 'sqlite:///'.str_replace('\\', '/', $databasePath);

        $_ENV['DATABASE_URL'] = $databaseUrl;
        $_SERVER['DATABASE_URL'] = $databaseUrl;
        putenv('DATABASE_URL='.$databaseUrl);
    }

    private static function cleanupChatStreams(): void
    {
        $streamDirectory = dirname(__DIR__).'/var/chat_streams';
        if (!is_dir($streamDirectory)) {
            return;
        }

        foreach (glob($streamDirectory.'/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
