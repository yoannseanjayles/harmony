<?php

namespace App\Chat\MessageHandler;

use App\AI\EmptyAIResponseException;
use App\Chat\ChatGenerationOrchestrator;
use App\Chat\ChatStreamSessionStore;
use App\Chat\Message\GenerateChatReplyMessage;
use App\Entity\ChatMessage;
use App\Entity\Project;
use App\Entity\Slide;
use App\Entity\User;
use App\Repository\ChatMessageRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Slide\SlideBuilder;
use App\Slide\UnsupportedSlideTypeException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/**
 * Handles the async AI generation triggered by GenerateChatReplyMessage.
 *
 * This handler runs in a Messenger worker process, freeing the PHP-FPM
 * worker that accepted the HTTP request.  SSE events are published to
 * the ChatStreamSessionStore so that the SSE endpoint can relay them
 * to the browser.
 */
#[AsMessageHandler]
final class GenerateChatReplyHandler
{
    private const PRIOR_MESSAGES_LIMIT = 8;
    private const MESSAGES_PER_PAGE = 10;

    public function __construct(
        private readonly ChatGenerationOrchestrator $orchestrator,
        private readonly ChatStreamSessionStore $chatStreamSessionStore,
        private readonly ChatMessageRepository $chatMessageRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly UserRepository $userRepository,
        private readonly SlideBuilder $slideBuilder,
        private readonly Environment $twig,
        #[Autowire(service: 'monolog.logger.ai')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateChatReplyMessage $message): void
    {
        $project = $this->projectRepository->find($message->getProjectId());
        $user = $this->userRepository->find($message->getUserId());
        $userMessage = $this->chatMessageRepository->find($message->getUserMessageId());

        if (!$project instanceof Project || !$user instanceof User || !$userMessage instanceof ChatMessage) {
            $this->chatStreamSessionStore->markStatus($message->getStreamId(), 'error');
            $this->chatStreamSessionStore->appendEvent($message->getStreamId(), 'error', [
                'message' => 'Session de generation introuvable.',
                'retryAvailable' => false,
                'userMessageId' => null,
            ]);

            return;
        }

        $streamId = $message->getStreamId();
        $lockHandle = $this->chatStreamSessionStore->acquireLock($streamId);

        if ($lockHandle === false) {
            // Another worker is already processing this stream — skip.
            return;
        }

        try {
            $this->chatStreamSessionStore->markStatus($streamId, 'streaming');

            $priorConversation = $this->chatMessageRepository->findConversationBeforeMessage(
                $project,
                $userMessage,
                self::PRIOR_MESSAGES_LIMIT,
            );

            $result = $this->orchestrator->generateStream(
                $project,
                $user,
                $userMessage,
                $priorConversation,
                function (string $type, array $payload) use ($streamId, $project): void {
                    $this->chatStreamSessionStore->appendEvent(
                        $streamId,
                        $type,
                        $this->buildSlideEventPayload($payload['slide'] ?? [], $project),
                    );
                },
            );

            $assistantMessage = $result->assistantMessage();
            $generationResult = $result->generationResult();
            $estimatedCostUsd = $this->orchestrator->calculateCostUsd($generationResult);

            $history = $this->chatMessageRepository->paginateProjectConversation(
                $project,
                1,
                self::MESSAGES_PER_PAGE,
            );

            $this->chatStreamSessionStore->appendEvent($streamId, 'generation_done', [
                'assistantHtml' => $this->twig->render('chat/_messages.html.twig', [
                    'messages' => [$assistantMessage],
                ]),
                'assistantMessageId' => $assistantMessage->getId(),
                'totalMessages' => $history['totalMessages'],
                'hasOlderMessages' => $history['hasOlderMessages'],
                'nextPage' => $history['nextPage'],
                'slidesCount' => $project->getSlidesCount(),
                'estimatedCostUsd' => number_format($estimatedCostUsd, 4, '.', ''),
                'pendingConfirmation' => $this->buildPendingConfirmationPayload($generationResult->pendingConfirmation()),
            ]);

            // Mark done only after the event is persisted so the SSE polling
            // loop never observes status=done without the generation_done event.
            $this->chatStreamSessionStore->markStatus($streamId, 'done', $assistantMessage->getId());
        } catch (\Throwable $e) {
            $isEmptyResponse = $e instanceof EmptyAIResponseException;

            $this->logger->error('chat_generation_failed', [
                'streamId' => $streamId,
                'projectId' => $message->getProjectId(),
                'userMessageId' => $message->getUserMessageId(),
                'exception' => $e->getMessage(),
                'exceptionClass' => $e::class,
            ]);

            $this->chatStreamSessionStore->markStatus($streamId, 'error');
            $this->chatStreamSessionStore->appendEvent($streamId, 'error', [
                'message' => $isEmptyResponse
                    ? 'Harmony n\'a pas recu de reponse de l\'IA. Vous pouvez relancer la generation.'
                    : 'Harmony n\'a pas pu terminer la generation en streaming.',
                'retryAvailable' => $isEmptyResponse,
                'userMessageId' => $message->getUserMessageId() > 0 ? $message->getUserMessageId() : null,
            ]);
        } finally {
            $this->chatStreamSessionStore->releaseLock($lockHandle);
        }
    }

    /**
     * Build the SSE slide payload and pre-warm the slide render cache.
     *
     * @param array<string, mixed> $slide
     *
     * @return array<string, mixed>
     */
    private function buildSlideEventPayload(array $slide, Project $project): array
    {
        if (($slide['removed'] ?? false) === true) {
            return [
                'slide' => [
                    'id' => (string) ($slide['id'] ?? ''),
                    'removed' => true,
                ],
                'html' => '',
            ];
        }

        $normalizedSlide = [
            'id' => (string) ($slide['id'] ?? ''),
            'title' => (string) ($slide['title'] ?? 'Slide'),
            'type' => (string) ($slide['type'] ?? 'content'),
            'body' => (string) ($slide['body'] ?? ''),
            'position' => (int) ($slide['position'] ?? 1),
        ];

        $previewHtml = null;
        $type = trim((string) ($slide['type'] ?? ''));
        if ($type !== '' && in_array($type, Slide::supportedTypes(), true)) {
            try {
                $transientSlide = $this->buildTransientSlide($slide, $project);
                $previewHtml = $this->slideBuilder->buildSlide($transientSlide);
            } catch (UnsupportedSlideTypeException) {
                // Fall through to lightweight preview below.
            } catch (\Throwable) {
                // Never let a cache warm-up failure break the stream.
            }
        }

        if ($previewHtml === null) {
            $previewHtml = $this->twig->render('project/_preview_slide.html.twig', [
                'slide' => $normalizedSlide,
            ]);
        }

        return [
            'slide' => $normalizedSlide,
            'html' => $previewHtml,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildTransientSlide(array $payload, Project $project): Slide
    {
        $type = trim((string) ($payload['type'] ?? 'content'));
        $title = trim((string) ($payload['title'] ?? ''));
        $subtitle = trim((string) ($payload['subtitle'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $items = array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $rawItems),
            static fn (string $item): bool => $item !== '',
        ));

        $content = match ($type) {
            Slide::TYPE_TITLE => ['label' => '', 'title' => $title, 'subtitle' => $subtitle],
            Slide::TYPE_CONTENT => ['title' => $title, 'body' => $body, 'items' => $items],
            Slide::TYPE_CLOSING => ['message' => $body ?: $title, 'cta_label' => '', 'cta_url' => ''],
            Slide::TYPE_QUOTE => ['quote' => $body ?: $title, 'author' => '', 'role' => '', 'source' => ''],
            Slide::TYPE_SPLIT => ['title' => $title, 'body' => $body, 'items' => $items, 'image_url' => '', 'image_alt' => '', 'layout' => 'text-left'],
            Slide::TYPE_IMAGE => ['image_url' => '', 'image_alt' => $title, 'overlay_text' => $title, 'caption' => ''],
            Slide::TYPE_TIMELINE => ['title' => $title, 'items' => []],
            Slide::TYPE_STATS => ['title' => $title, 'stats' => []],
            Slide::TYPE_COMPARISON => ['title' => $title, 'left' => ['heading' => '', 'items' => [], 'highlight' => ''], 'right' => ['heading' => '', 'items' => [], 'highlight' => '']],
            default => ['title' => $title, 'body' => $body, 'items' => $items],
        };

        return (new Slide())
            ->setProject($project)
            ->setType($type)
            ->setContent($content)
            ->setPosition((int) ($payload['position'] ?? 1));
    }

    /**
     * @param array<string, mixed>|null $pendingConfirmation
     *
     * @return array<string, mixed>|null
     */
    private function buildPendingConfirmationPayload(?array $pendingConfirmation): ?array
    {
        if ($pendingConfirmation === null) {
            return null;
        }

        $summary = trim((string) ($pendingConfirmation['summary'] ?? ''));
        $assistantMessage = trim((string) ($pendingConfirmation['assistant_message'] ?? ''));

        return [
            'summary' => $summary !== '' ? $summary : $assistantMessage,
            'assistantMessage' => $assistantMessage,
            'actionsCount' => count(is_array($pendingConfirmation['proposed_actions'] ?? null) ? $pendingConfirmation['proposed_actions'] : []),
        ];
    }
}
