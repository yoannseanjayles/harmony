<?php

namespace App\Entity;

use App\Repository\ProjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Project
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DELETED = 'deleted';

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotBlank(message: 'project.title.required')]
    #[Assert\Length(min: 3, minMessage: 'project.title.min_length', max: 160)]
    #[ORM\Column(length: 160)]
    private string $title = '';

    #[Assert\Choice(callback: [self::class, 'providerValues'], message: 'project.provider.invalid')]
    #[ORM\Column(length: 40)]
    private string $provider = 'openai';

    #[Assert\Choice(callback: [self::class, 'modelValues'], message: 'project.model.invalid')]
    #[ORM\Column(length: 80)]
    private string $model = 'gpt-4.1-mini';

    #[Assert\Choice(callback: [self::class, 'allowedStatusValues'], message: 'project.status.invalid')]
    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: Types::TEXT, options: ['default' => '[]'])]
    private string $slidesJson = '[]';

    #[ORM\Column(type: Types::TEXT, options: ['default' => '{}'])]
    private string $themeConfigJson = '{}';

    /**
     * T201 — Stores the user's manual token overrides delta, separate from the applied preset
     * base tokens kept in themeConfigJson.  The effective theme is the merge of both.
     */
    #[ORM\Column(type: Types::TEXT, options: ['default' => '{}'])]
    private string $themeOverridesJson = '{}';

    /**
     * T203 — Monotonically-increasing version counter, incremented by ThemeEngine each time
     * the project's theme (base or overrides) is modified.  Included in the slide renderHash
     * so that theme-only changes correctly invalidate the render cache (T204).
     */
    #[ORM\Column(options: ['default' => 1])]
    private int $themeVersion = 1;

    #[ORM\Column(type: Types::TEXT, options: ['default' => '{}'])]
    private string $metadataJson = '{}';

    #[ORM\Column(type: Types::TEXT, options: ['default' => '[]'])]
    private string $mediaRefsJson = '[]';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $shareToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $shareExpiresAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isPublic = false;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = trim($title);

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderLabelKey(): string
    {
        return array_search($this->provider, self::providerChoices(), true) ?: $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getModelLabelKey(): string
    {
        return array_search($this->model, self::modelChoices(), true) ?: $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSlides(): array
    {
        return $this->decodeJsonArray($this->slidesJson);
    }

    /**
     * @param list<array<string, mixed>> $slides
     */
    public function setSlides(array $slides): self
    {
        $this->slidesJson = $this->encodeJson($slides, '[]');

        return $this;
    }

    public function getThemeConfigJson(): string
    {
        return $this->themeConfigJson;
    }

    /**
     * @return array<string, mixed>
     */
    public function getThemeConfig(): array
    {
        return $this->decodeJsonObject($this->themeConfigJson);
    }

    /**
     * @param array<string, mixed> $themeConfig
     */
    public function setThemeConfig(array $themeConfig): self
    {
        $this->themeConfigJson = $this->encodeJson($themeConfig, '{}');

        return $this;
    }

    // ── T201 — Per-project user overrides ────────────────────────────────────

    public function getThemeOverridesJson(): string
    {
        return $this->themeOverridesJson;
    }

    /**
     * @return array<string, mixed>
     */
    public function getThemeOverrides(): array
    {
        return $this->decodeJsonObject($this->themeOverridesJson);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function setThemeOverrides(array $overrides): self
    {
        $this->themeOverridesJson = $this->encodeJson($overrides, '{}');

        return $this;
    }

    // ── T202 — Effective theme (preset base + user overrides merged) ─────────

    /**
     * Return the effective theme JSON: the base preset tokens merged with the user's overrides.
     * This is what ThemeEngine::toCssBlock() and SlideRenderHashCalculator should use.
     */
    public function getEffectiveThemeConfigJson(): string
    {
        if ($this->themeOverridesJson === '{}' || $this->themeOverridesJson === '') {
            return $this->themeConfigJson;
        }

        $base      = $this->getThemeConfig();
        $overrides = $this->getThemeOverrides();

        if ($overrides === []) {
            return $this->themeConfigJson;
        }

        $merged = array_merge($base, $overrides);

        try {
            return (string) json_encode($merged, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->themeConfigJson;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getEffectiveThemeConfig(): array
    {
        return $this->decodeJsonObject($this->getEffectiveThemeConfigJson());
    }

    // ── T203 — Theme version counter ─────────────────────────────────────────

    public function getThemeVersion(): int
    {
        return $this->themeVersion;
    }

    /**
     * Increment the theme version.  Called by ThemeEngine whenever the base preset or
     * user overrides change so that the slide renderHash is correctly invalidated (T204).
     */
    public function incrementThemeVersion(): self
    {
        ++$this->themeVersion;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->decodeJsonObject($this->metadataJson);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): self
    {
        $this->metadataJson = $this->encodeJson($metadata, '{}');

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getMediaRefs(): array
    {
        return $this->decodeJsonArray($this->mediaRefsJson);
    }

    /**
     * @param list<array<string, mixed>> $mediaRefs
     */
    public function setMediaRefs(array $mediaRefs): self
    {
        $this->mediaRefsJson = $this->encodeJson($mediaRefs, '[]');

        return $this;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function setArchivedAt(?\DateTimeImmutable $archivedAt): self
    {
        $this->archivedAt = $archivedAt;

        return $this;
    }

    public function archive(): self
    {
        $this->archivedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function restore(): self
    {
        $this->archivedAt = null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    public function getShareToken(): ?string
    {
        return $this->shareToken;
    }

    public function setShareToken(?string $shareToken): self
    {
        $this->shareToken = $shareToken;

        return $this;
    }

    public function getShareExpiresAt(): ?\DateTimeImmutable
    {
        return $this->shareExpiresAt;
    }

    public function setShareExpiresAt(?\DateTimeImmutable $shareExpiresAt): self
    {
        $this->shareExpiresAt = $shareExpiresAt;

        return $this;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): self
    {
        $this->isPublic = $isPublic;

        return $this;
    }

    public function activateShare(string $token, \DateTimeImmutable $expiresAt): self
    {
        $this->shareToken = $token;
        $this->shareExpiresAt = $expiresAt;
        $this->isPublic = true;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function revokeShare(): self
    {
        $this->shareToken = null;
        $this->shareExpiresAt = null;
        $this->isPublic = false;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function hasActiveShareLink(?\DateTimeImmutable $now = null): bool
    {
        if (!$this->isPublic || $this->shareToken === null || !$this->shareExpiresAt instanceof \DateTimeImmutable) {
            return false;
        }

        $referenceTime = $now ?? new \DateTimeImmutable();

        return $this->shareExpiresAt >= $referenceTime;
    }

    public function getSlidesCount(): int
    {
        return count($this->getSlides());
    }

    public function getMediaRefsCount(): int
    {
        return count($this->getMediaRefs());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPendingConfirmation(): ?array
    {
        $chatMetadata = $this->chatMetadataFrom($this->getMetadata());
        $pendingConfirmation = $chatMetadata['pending_confirmation'] ?? null;

        return is_array($pendingConfirmation) ? $pendingConfirmation : null;
    }

    public function hasPendingConfirmation(): bool
    {
        return $this->getPendingConfirmation() !== null;
    }

    /**
     * @param array<string, mixed> $pendingConfirmation
     */
    public function storePendingConfirmation(array $pendingConfirmation): self
    {
        $metadata = $this->getMetadata();
        $chatMetadata = $this->chatMetadataFrom($metadata);
        $chatMetadata['pending_confirmation'] = $pendingConfirmation;
        $metadata['chat'] = $chatMetadata;

        return $this->setMetadata($metadata);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolvePendingConfirmation(string $decision): ?array
    {
        $metadata = $this->getMetadata();
        $chatMetadata = $this->chatMetadataFrom($metadata);
        $pendingConfirmation = is_array($chatMetadata['pending_confirmation'] ?? null) ? $chatMetadata['pending_confirmation'] : null;

        if ($pendingConfirmation === null) {
            return null;
        }

        unset($chatMetadata['pending_confirmation']);
        $chatMetadata['last_confirmation'] = [
            'decision' => $decision,
            'summary' => trim((string) ($pendingConfirmation['summary'] ?? '')),
            'assistant_message' => trim((string) ($pendingConfirmation['assistant_message'] ?? '')),
            'proposed_actions' => is_array($pendingConfirmation['proposed_actions'] ?? null) ? $pendingConfirmation['proposed_actions'] : [],
            'resolved_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        if ($chatMetadata === []) {
            unset($metadata['chat']);
        } else {
            $metadata['chat'] = $chatMetadata;
        }

        $this->setMetadata($metadata);

        return $pendingConfirmation;
    }

    /**
     * @return array<string, mixed>
     */
    public function toVersionSnapshot(): array
    {
        return [
            'title' => $this->getTitle(),
            'provider' => $this->getProvider(),
            'model' => $this->getModel(),
            'status' => $this->getStatus(),
            'slides' => $this->getSlides(),
            'themeConfig' => $this->getThemeConfig(),
            'themeOverrides' => $this->getThemeOverrides(),
            'themeVersion' => $this->getThemeVersion(),
            'metadata' => $this->getMetadata(),
            'mediaRefs' => $this->getMediaRefs(),
            'archivedAt' => $this->getArchivedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function restoreFromVersionSnapshot(array $snapshot): self
    {
        $this
            ->setTitle((string) ($snapshot['title'] ?? $this->getTitle()))
            ->setProvider((string) ($snapshot['provider'] ?? $this->getProvider()))
            ->setModel((string) ($snapshot['model'] ?? $this->getModel()))
            ->setStatus((string) ($snapshot['status'] ?? $this->getStatus()))
            ->setSlides(is_array($snapshot['slides'] ?? null) ? $snapshot['slides'] : $this->getSlides())
            ->setThemeConfig(is_array($snapshot['themeConfig'] ?? null) ? $snapshot['themeConfig'] : $this->getThemeConfig())
            ->setThemeOverrides(is_array($snapshot['themeOverrides'] ?? null) ? $snapshot['themeOverrides'] : $this->getThemeOverrides())
            ->setMetadata(is_array($snapshot['metadata'] ?? null) ? $snapshot['metadata'] : $this->getMetadata())
            ->setMediaRefs(is_array($snapshot['mediaRefs'] ?? null) ? $snapshot['mediaRefs'] : $this->getMediaRefs())
            ->setArchivedAt($this->parseArchivedAtValue($snapshot['archivedAt'] ?? null))
        ;

        return $this;
    }

    public function markDeleted(): self
    {
        $this->status = self::STATUS_DELETED;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->status === self::STATUS_DELETED;
    }

    /**
     * @return array<string, string>
     */
    public static function providerChoices(): array
    {
        return [
            'project.provider.openai' => 'openai',
            'project.provider.anthropic' => 'anthropic',
        ];
    }

    /**
     * @return list<string>
     */
    public static function providerValues(): array
    {
        return array_values(self::providerChoices());
    }

    /**
     * @return array<string, string>
     */
    public static function modelChoices(): array
    {
        return [
            'project.model.gpt41mini' => 'gpt-4.1-mini',
            'project.model.gpt41' => 'gpt-4.1',
            'project.model.claude37' => 'claude-3-7-sonnet',
            'project.model.claude35' => 'claude-3-5-sonnet',
        ];
    }

    /**
     * @return list<string>
     */
    public static function modelValues(): array
    {
        return array_values(self::modelChoices());
    }

    /**
     * @return list<string>
     */
    public static function allowedStatusValues(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_ACTIVE,
            self::STATUS_DELETED,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusChoices(): array
    {
        return [
            'project.status.draft' => self::STATUS_DRAFT,
            'project.status.active' => self::STATUS_ACTIVE,
        ];
    }

    private function encodeJson(array $data, string $fallback): string
    {
        try {
            return (string) json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $fallback;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeJsonArray(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function parseArchivedAtValue(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function chatMetadataFrom(array $metadata): array
    {
        $chatMetadata = $metadata['chat'] ?? null;

        return is_array($chatMetadata) ? $chatMetadata : [];
    }
}
