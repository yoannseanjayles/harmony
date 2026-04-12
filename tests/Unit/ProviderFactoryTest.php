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
    private function buildFactory(
        UserApiKeyManager $userApiKeyManager,
        string $platformDefault = 'sk-platform-default',
        string $openAiPlatformKey = 'sk-platform-openai',
        string $anthropicPlatformKey = 'sk-platform-anthropic',
    ): ProviderFactory {
        return new ProviderFactory(
            $userApiKeyManager,
            new RecordingAIHttpClient(),
            new RecordingAIHttpClient(),
            $platformDefault,
            $openAiPlatformKey,
            $anthropicPlatformKey,
            'https://openai.test/v1',
            'https://anthropic.test/v1',
        );
    }

    public function testResolveCredentialUsesUserByokWhenAvailable(): void
    {
        $user = (new User())->setEmail('byok@harmony.test');
        $userApiKeyManager = $this->createMock(UserApiKeyManager::class);
        $userApiKeyManager->method('hasUserApiKey')->with($user)->willReturn(true);
        $userApiKeyManager->method('revealUserApiKey')->with($user)->willReturn('sk-user-1234');

        $credential = $this->buildFactory($userApiKeyManager)->resolveCredential($user, 'openai');

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

        $credential = $this->buildFactory($userApiKeyManager)->resolveCredential($user, 'anthropic');

        self::assertSame('platform', $credential->source());
        self::assertSame('anthropic', $credential->provider());
        self::assertSame('sk-platform-anthropic', $credential->reveal());
    }

    public function testResolveCredentialFallsBackToGenericPlatformKeyWhenSpecificKeyIsEmpty(): void
    {
        $user = (new User())->setEmail('generic@harmony.test');
        $userApiKeyManager = $this->createMock(UserApiKeyManager::class);
        $userApiKeyManager->method('hasUserApiKey')->with($user)->willReturn(false);

        $factory = $this->buildFactory($userApiKeyManager, 'sk-platform-default', '', '');

        self::assertSame('sk-platform-default', $factory->resolveCredential($user, 'openai')->reveal());
        self::assertSame('sk-platform-default', $factory->resolveCredential($user, 'anthropic')->reveal());
    }

    public function testCreateForProviderReturnsOpenAiProvider(): void
    {
        $userApiKeyManager = $this->createMock(UserApiKeyManager::class);
        $userApiKeyManager->method('hasUserApiKey')->willReturn(false);

        self::assertInstanceOf(OpenAIProvider::class, $this->buildFactory($userApiKeyManager)->createForProvider('openai', null));
    }

    public function testCreateForProjectReturnsClaudeProviderFromProjectConfiguration(): void
    {
        $userApiKeyManager = $this->createMock(UserApiKeyManager::class);
        $userApiKeyManager->method('hasUserApiKey')->willReturn(false);

        $project = (new Project())
            ->setTitle('Projet Claude')
            ->setProvider('anthropic')
            ->setModel('claude-3-7-sonnet');

        self::assertInstanceOf(ClaudeProvider::class, $this->buildFactory($userApiKeyManager)->createForProject($project, null));
    }
}
