<?php

namespace App\AI;

use App\Entity\ChatMessage;
use App\Entity\Project;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ChatEngine
{
    public function __construct(
        private readonly ProviderFactory $providerFactory,
        private readonly PromptBuilder $promptBuilder,
        private readonly RetryPolicy $retryPolicy,
        #[Autowire(service: 'monolog.logger.ai')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<ChatMessage> $conversationHistory
     */
    public function generateAssistantReply(Project $project, User $user, string $userMessage, array $conversationHistory = []): ChatGenerationResult
    {
        $provider = $this->providerFactory->createForProject($project, $user);
        $promptRequest = $this->promptBuilder->build($project, $userMessage, $conversationHistory);
        $validatedResponse = $this->retryPolicy->sendWithRetry($provider, $promptRequest);

        return $this->applyValidatedResponse(
            $project,
            $validatedResponse,
            static function (): void {},
        );
    }

    /**
     * @param list<ChatMessage> $conversationHistory
     * @param callable(string, array<string, mixed>): void $onEvent
     */
    public function streamAssistantReply(Project $project, User $user, ChatMessage $userMessage, array $conversationHistory, callable $onEvent): ChatGenerationResult
    {
        $provider = $this->providerFactory->createForProject($project, $user);
        $promptRequest = $this->promptBuilder->build($project, $userMessage->getContent(), $conversationHistory);
        $validatedResponse = $this->retryPolicy->streamWithRetry($provider, $promptRequest);

        return $this->applyValidatedResponse($project, $validatedResponse, $onEvent);
    }

    /**
     * @param list<array<string, mixed>> $actions
     *
     * @return array{slides: list<array<string, mixed>>, changed: bool}
     */
    public function applyConfirmedActions(Project $project, array $actions): array
    {
        return $this->applyActions($project, $actions, static function (): void {});
    }

    /**
     * @param callable(string, array<string, mixed>): void $onEvent
     */
    private function applyValidatedResponse(Project $project, ValidatedProviderResponse $validatedResponse, callable $onEvent): ChatGenerationResult
    {
        $pendingConfirmation = $this->buildPendingConfirmation($validatedResponse->assistantMessage(), $validatedResponse->actions());
        if ($pendingConfirmation !== null) {
            return new ChatGenerationResult(
                $validatedResponse->providerResponse(),
                $validatedResponse->assistantMessage(),
                $this->normalizeSlides($project->getSlides()),
                false,
                $pendingConfirmation,
                $validatedResponse->attemptCount(),
            );
        }

        $appliedActions = $this->applyActions($project, $validatedResponse->actions(), $onEvent);

        return new ChatGenerationResult(
            $validatedResponse->providerResponse(),
            $validatedResponse->assistantMessage(),
            $appliedActions['slides'],
            $appliedActions['changed'],
            null,
            $validatedResponse->attemptCount(),
        );
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @param callable(string, array<string, mixed>): void $onEvent
     *
     * @return array{slides: list<array<string, mixed>>, changed: bool}
     */
    private function applyActions(Project $project, array $actions, callable $onEvent): array
    {
        $currentSlides = $this->normalizeSlides($project->getSlides());
        $workingSlides = $currentSlides;

        foreach ($actions as $action) {
            $actionType = (string) ($action['action'] ?? '');

            switch ($actionType) {
                case 'add_slide':
                    $this->applyAddSlide($workingSlides, $action, $onEvent);
                    break;
                case 'update_slide':
                    $this->applyUpdateSlide($workingSlides, $action, $onEvent);
                    break;
                case 'remove_slide':
                    $this->applyRemoveSlide($workingSlides, $action, $onEvent);
                    break;
                case 'reorder_slides':
                    $this->applyReorderSlides($workingSlides, $action, $onEvent);
                    break;
            }
        }

        $normalizedSlides = $this->reindexSlides($workingSlides);

        return [
            'slides' => $normalizedSlides,
            'changed' => $normalizedSlides !== $currentSlides,
        ];
    }

    /**
     * @param list<array<string, mixed>> $slides
     * @param array<string, mixed> $action
     * @param callable(string, array<string, mixed>): void $onEvent
     */
    private function applyAddSlide(array &$slides, array $action, callable $onEvent): void
    {
        $slide = $this->normalizeSlide((array) ($action['slide'] ?? []), count($slides) + 1);
        $position = isset($action['position']) ? max(1, (int) $action['position']) : count($slides) + 1;
        $position = min($position, count($slides) + 1);

        $slide['id'] = $this->ensureUniqueSlideId($slides, (string) $slide['id']);

        array_splice($slides, $position - 1, 0, [$slide]);
        $slides = $this->reindexSlides($slides);

        $onEvent('slide_added', [
            'slide' => $this->renderableSlide($slide),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $slides
     * @param array<string, mixed> $action
     * @param callable(string, array<string, mixed>): void $onEvent
     */
    private function applyUpdateSlide(array &$slides, array $action, callable $onEvent): void
    {
        $slideId = (string) ($action['slide_id'] ?? '');
        $index = $this->findSlideIndex($slides, $slideId);
        if ($index === null) {
            $this->logger->warning('ai_response_action_skipped', [
                'reason' => 'slide_not_found',
                'action' => $action,
            ]);

            return;
        }

        $existingSlide = $slides[$index];
        $changes = (array) ($action['changes'] ?? []);
        $updatedSlide = $existingSlide;

        foreach (['title', 'type', 'subtitle', 'body', 'notes'] as $field) {
            if (array_key_exists($field, $changes) && is_string($changes[$field])) {
                $updatedSlide[$field] = trim($changes[$field]);
            }
        }

        if (array_key_exists('items', $changes) && is_array($changes['items'])) {
            $updatedSlide['items'] = array_values(array_map(
                static fn (string $item): string => trim($item),
                array_filter($changes['items'], static fn (mixed $item): bool => is_string($item) && trim($item) !== ''),
            ));
        }

        $slides[$index] = $this->normalizeSlide($updatedSlide, $index + 1, $existingSlide['id']);

        if (isset($changes['position'])) {
            $targetPosition = max(1, min(count($slides), (int) $changes['position']));
            $movedSlide = $slides[$index];
            array_splice($slides, $index, 1);
            array_splice($slides, $targetPosition - 1, 0, [$movedSlide]);
        }

        $slides = $this->reindexSlides($slides);
        $updatedIndex = $this->findSlideIndex($slides, $existingSlide['id']) ?? $index;

        $onEvent('slide_updated', [
            'slide' => $this->renderableSlide($slides[$updatedIndex]),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $slides
     * @param array<string, mixed> $action
     * @param callable(string, array<string, mixed>): void $onEvent
     */
    private function applyRemoveSlide(array &$slides, array $action, callable $onEvent): void
    {
        $slideId = (string) ($action['slide_id'] ?? '');
        $index = $this->findSlideIndex($slides, $slideId);
        if ($index === null) {
            $this->logger->warning('ai_response_action_skipped', [
                'reason' => 'slide_not_found',
                'action' => $action,
            ]);

            return;
        }

        $removedSlide = $slides[$index];
        array_splice($slides, $index, 1);
        $slides = $this->reindexSlides($slides);

        $onEvent('slide_updated', [
            'slide' => [
                'id' => $removedSlide['id'],
                'removed' => true,
            ],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $slides
     * @param array<string, mixed> $action
     * @param callable(string, array<string, mixed>): void $onEvent
     */
    private function applyReorderSlides(array &$slides, array $action, callable $onEvent): void
    {
        $requestedOrder = array_values(array_filter(
            is_array($action['slide_ids'] ?? null) ? $action['slide_ids'] : [],
            static fn (mixed $slideId): bool => is_string($slideId) && trim($slideId) !== '',
        ));

        if ($requestedOrder === []) {
            return;
        }

        $slidesById = [];
        foreach ($slides as $slide) {
            $slidesById[(string) ($slide['id'] ?? '')] = $slide;
        }

        $orderedSlides = [];
        foreach ($requestedOrder as $slideId) {
            if (!array_key_exists($slideId, $slidesById)) {
                $this->logger->warning('ai_response_action_skipped', [
                    'reason' => 'slide_not_found',
                    'action' => $action,
                ]);
                continue;
            }

            $orderedSlides[] = $slidesById[$slideId];
            unset($slidesById[$slideId]);
        }

        if ($orderedSlides === []) {
            return;
        }

        $slides = array_values(array_merge($orderedSlides, array_values($slidesById)));
        $slides = $this->reindexSlides($slides);

        foreach ($slides as $slide) {
            $onEvent('slide_updated', [
                'slide' => $this->renderableSlide($slide),
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $slides
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeSlides(array $slides): array
    {
        $normalized = [];

        foreach ($slides as $index => $slide) {
            if (!is_array($slide)) {
                continue;
            }

            $normalized[] = $this->normalizeSlide($slide, count($normalized) + 1, (string) ($slide['id'] ?? 'slide-'.($index + 1)));
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $slide
     *
     * @return array<string, mixed>
     */
    private function normalizeSlide(array $slide, int $position, ?string $forcedId = null): array
    {
        $title = trim((string) ($slide['title'] ?? 'Slide '.$position));
        $type = trim((string) ($slide['type'] ?? 'content'));
        $rawItems = is_array($slide['items'] ?? null) ? $slide['items'] : [];
        $items = array_values(array_map(
            static fn (string $item): string => trim($item),
            array_filter($rawItems, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''),
        ));

        $normalized = [
            'id' => $forcedId ?? $this->buildSlideIdentifier((string) ($slide['id'] ?? $title), $position),
            'title' => $title,
            'type' => $type,
            'subtitle' => trim((string) ($slide['subtitle'] ?? '')),
            'body' => trim((string) ($slide['body'] ?? '')),
            'items' => $items,
            'notes' => trim((string) ($slide['notes'] ?? '')),
            'position' => $position,
        ];

        if ($normalized['body'] === '' && $items !== []) {
            $normalized['body'] = implode("\n", array_map(static fn (string $item): string => '- '.$item, $items));
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $slides
     *
     * @return list<array<string, mixed>>
     */
    private function reindexSlides(array $slides): array
    {
        return array_values(array_map(function (array $slide, int $index): array {
            $slide['position'] = $index + 1;

            return $slide;
        }, $slides, array_keys($slides)));
    }

    /**
     * @param list<array<string, mixed>> $slides
     */
    private function ensureUniqueSlideId(array $slides, string $preferredId): string
    {
        $existingIds = array_map(static fn (array $slide): string => (string) ($slide['id'] ?? ''), $slides);
        if (!in_array($preferredId, $existingIds, true)) {
            return $preferredId;
        }

        $suffix = 2;
        do {
            $candidate = $preferredId.'-'.$suffix;
            ++$suffix;
        } while (in_array($candidate, $existingIds, true));

        return $candidate;
    }

    /**
     * @param list<array<string, mixed>> $slides
     */
    private function findSlideIndex(array $slides, string $slideId): ?int
    {
        foreach ($slides as $index => $slide) {
            if ((string) ($slide['id'] ?? '') === $slideId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $slide
     *
     * @return array<string, mixed>
     */
    private function renderableSlide(array $slide): array
    {
        return [
            'id' => (string) ($slide['id'] ?? ''),
            'title' => (string) ($slide['title'] ?? 'Slide'),
            'type' => (string) ($slide['type'] ?? 'content'),
            'subtitle' => (string) ($slide['subtitle'] ?? ''),
            'body' => (string) ($slide['body'] ?? ''),
            'items' => $slide['items'] ?? [],
            'notes' => (string) ($slide['notes'] ?? ''),
            'position' => (int) ($slide['position'] ?? 1),
        ];
    }

    private function buildSlideIdentifier(string $value, int $fallbackIndex): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii ?: $value), '-'));

        return $slug !== '' ? $slug : 'slide-'.$fallbackIndex;
    }

    /**
     * @param list<array<string, mixed>> $actions
     *
     * @return array<string, mixed>|null
     */
    private function buildPendingConfirmation(string $assistantMessage, array $actions): ?array
    {
        foreach ($actions as $action) {
            if (($action['action'] ?? null) !== 'request_confirmation') {
                continue;
            }

            return [
                'summary' => trim((string) ($action['summary'] ?? $assistantMessage)),
                'assistant_message' => trim($assistantMessage),
                'proposed_actions' => is_array($action['proposed_actions'] ?? null) ? $action['proposed_actions'] : [],
                'requested_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ];
        }

        return null;
    }
}
