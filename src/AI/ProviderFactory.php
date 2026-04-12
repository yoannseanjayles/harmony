<?php

namespace App\AI;

use App\AI\Http\AIHttpClientInterface;
use App\Entity\User;
use App\Entity\Project;
use App\Security\UserApiKeyManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ProviderFactory
{
    public function __construct(
        private readonly UserApiKeyManager $userApiKeyManager,
        private readonly AIHttpClientInterface $httpClient,
        #[Autowire('%env(string:HARMONY_PLATFORM_API_KEY)%')]
        private readonly string $platformApiKeyFallback,
        #[Autowire('%env(string:HARMONY_PLATFORM_OPENAI_API_KEY)%')]
        private readonly string $openAiPlatformApiKey,
        #[Autowire('%env(string:HARMONY_PLATFORM_ANTHROPIC_API_KEY)%')]
        private readonly string $anthropicPlatformApiKey,
        #[Autowire('%env(string:HARMONY_OPENAI_BASE_URL)%')]
        private readonly string $openAiBaseUrl,
        #[Autowire('%env(string:HARMONY_ANTHROPIC_BASE_URL)%')]
        private readonly string $anthropicBaseUrl,
    ) {
    }

    public function createForProject(Project $project, ?User $user): AIProviderInterface
    {
        return $this->createForProvider($project->getProvider(), $user);
    }

    public function createForProvider(string $provider, ?User $user): AIProviderInterface
    {
        $credential = $this->resolveCredential($user, $provider);

        return match ($provider) {
            'anthropic' => new ClaudeProvider($this->httpClient, $credential, $this->anthropicBaseUrl),
            'openai' => new OpenAIProvider($this->httpClient, $credential, $this->openAiBaseUrl),
            default => throw new \InvalidArgumentException(sprintf('Unsupported AI provider "%s".', $provider)),
        };
    }

    public function resolveCredential(?User $user, string $provider = 'openai'): ApiCredential
    {
        if ($user instanceof User && $this->userApiKeyManager->hasUserApiKey($user)) {
            $plainTextApiKey = $this->userApiKeyManager->revealUserApiKey($user);

            if ($plainTextApiKey !== null) {
                return new ApiCredential($provider, 'byok', $plainTextApiKey);
            }
        }

        $platformApiKey = trim(match ($provider) {
            'anthropic' => $this->anthropicPlatformApiKey !== '' ? $this->anthropicPlatformApiKey : $this->platformApiKeyFallback,
            'openai' => $this->openAiPlatformApiKey !== '' ? $this->openAiPlatformApiKey : $this->platformApiKeyFallback,
            default => $this->platformApiKeyFallback,
        });

        if ($platformApiKey === '') {
            throw new \RuntimeException('No platform API key configured and no BYOK key available.');
        }

        return new ApiCredential($provider, 'platform', $platformApiKey);
    }
}
