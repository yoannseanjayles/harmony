<?php

namespace App\Tests\Unit;

use App\AI\ClaudeProvider;
use App\AI\ProviderFactory;
use App\AI\OpenAIProvider;
use App\Entity\Project;
use App\Entity\User;
use App\Security\UserApiKeyManager;
use App\Tests\Support\AI\RecordingAIHttpClient;
use PHPUnit\Framework\TestCase;

final class ProviderFactoryTest extends TestCase
{
    public function testResolveCredentialUsesUserByokWhenAvailable(): void
    {
        $user = (new User())->setEmail('byok@harmony.test');
        $userApiKeyManager = $this->createMock(UserApiKeyManager::class);
        $userApiKeyManager->method('hasUserApiKey')->with($user)->willReturn(true);
        $userApiKeyManager->method('revealUserApiKey')->with($user)->willReturn('sk-user-1234');

        $factory = new ProviderFactory(
            $userApiKeyManager,
            new RecordingAIHttpClient(),
            'sk-platform-default',
            'sk-platform-openai',
            'sk-platform-anthropic',
            'https://openai.test/v1',
            'https://anthropic.test/v1',
        );
        $credential = $factory->resolveCredential($user, 'openai');

        self::assertSame('byok', $credential->source());
        self::assertSame('sk-user-1234', $credential->reveal());
        self::assertStringNotContainsString('sk-user-1234', json_encode($credential, JSON_THROW_ON_ERROR));
        self::assertSame('****1234', $credential->masked());
    }

    public function testResolveCredentialFallsBackToProviderSpecificPlatformKey(): void
    {
        $user = (new User())->setEmail('platform@harmony.test');
        $userApiKeyManager = $this->createMock(UserApiKeyManager::class);
        $userApiKeyManager->method('hasUserApiKey')->with($user)->willReturn(false);

        $factory = new ProviderFactory(
            $userApiKeyManager,
            new RecordingAIHttpClient(),
            'sk-platform-default',
            'sk-platform-openai',
            'sk-platform-anthropic',
            'https://openai.test/v1',
            'https://anthropic.test/v1',
        );
        $credential = $factory->resolveCredential($user, 'anthropic');

        self::assertSame('platform', $credential->source());
        self::assertSame('anthropic', $credential->provider());
        self::assertSame('sk-platform-anthropic', $credential->reveal());
    }

    public function testResolveCredentialFallsBackToGenericPlatformKeyWhenSpecificKeyIsEmpty(): void
    {
        $user = (new User())->setEmail('generic@harmony.test');
        $userApiKeyManager = $this->createMock(UserApiKeyManager::class);
        $userApiKeyManager->method('hasUserApiKey')->with($user)->willReturn(false);

        $factory = new ProviderFactory(
            $userApiKeyManager,
            new RecordingAIHttpClient(),
            'sk-platform-default',
            '',
            '',
            'https://openai.test/v1',
            'https://anthropic.test/v1',
        );

        self::assertSame('sk-platform-default', $factory->resolveCredential($user, 'openai')->reveal());
        self::assertSame('sk-platform-default', $factory->resolveCredential($user, 'anthropic')->reveal());
    }

    public function testCreateForProviderReturnsOpenAiProvider(): void
    {
        $userApiKeyManager = $this->createMock(UserApiKeyManager::class);
        $userApiKeyManager->method('hasUserApiKey')->willReturn(false);

        $factory = new ProviderFactory(
            $userApiKeyManager,
            new RecordingAIHttpClient(),
            'sk-platform-default',
            'sk-platform-openai',
            'sk-platform-anthropic',
            'https://openai.test/v1',
            'https://anthropic.test/v1',
        );

        self::assertInstanceOf(OpenAIProvider::class, $factory->createForProvider('openai', null));
    }

    public function testCreateForProjectReturnsClaudeProviderFromProjectConfiguration(): void
    {
        $userApiKeyManager = $this->createMock(UserApiKeyManager::class);
        $userApiKeyManager->method('hasUserApiKey')->willReturn(false);

        $factory = new ProviderFactory(
            $userApiKeyManager,
            new RecordingAIHttpClient(),
            'sk-platform-default',
            'sk-platform-openai',
            'sk-platform-anthropic',
            'https://openai.test/v1',
            'https://anthropic.test/v1',
        );

        $project = (new Project())
            ->setTitle('Projet Claude')
            ->setProvider('anthropic')
            ->setModel('claude-3-7-sonnet');

        self::assertInstanceOf(ClaudeProvider::class, $factory->createForProject($project, null));
    }
}
