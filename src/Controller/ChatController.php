<?php

namespace App\Controller;

use App\AI\ChatCompletionService;
use App\AI\ChatEngine;
use App\Entity\ChatMessage;
use App\Entity\Project;
use App\Entity\User;
use App\Chat\ChatStreamSessionStore;
use App\Project\ProjectVersioning;
use App\Repository\ChatMessageRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/projects/{projectId}/chat', requirements: ['projectId' => '\d+'])]
final class ChatController extends AbstractController
{
    private const MESSAGES_PER_PAGE = 10;
    private const STREAM_RETRY_MILLISECONDS = 1000;
    private const STREAM_POLLING_MICROSECONDS = 150000;

    #[Route('/history', name: 'app_chat_history', methods: ['GET'])]
    public function history(int $projectId, Request $request, ProjectRepository $projectRepository, ChatMessageRepository $chatMessageRepository): JsonResponse
    {
        $project = $this->findOwnedProjectOr404($projectId, $projectRepository);
        $history = $chatMessageRepository->paginateProjectConversation(
            $project,
            $request->query->getInt('page', 1),
            self::MESSAGES_PER_PAGE,
        );

        return $this->json([
            'html' => $this->renderView('chat/_messages.html.twig', [
                'messages' => $history['messages'],
            ]),
            'page' => $history['page'],
            'totalPages' => $history['totalPages'],
            'totalMessages' => $history['totalMessages'],
            'hasOlderMessages' => $history['hasOlderMessages'],
            'nextPage' => $history['nextPage'],
        ]);
    }

    #[Route('/send-message', name: 'app_chat_send_message', methods: ['POST'])]
    public function sendMessage(
        int $projectId,
        Request $request,
        ProjectRepository $projectRepository,
        ChatMessageRepository $chatMessageRepository,
        ChatStreamSessionStore $chatStreamSessionStore,
        ChatCompletionService $chatCompletionService,
        ProjectVersioning $projectVersioning,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
    ): Response {
        $project = $this->findOwnedProjectOr404($projectId, $projectRepository);
        $user = $this->requireUser();
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('chat_send_'.$project->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        if ($project->hasPendingConfirmation()) {
            return $this->pendingConfirmationBlockedResponse($request, $project);
        }

        $priorConversation = $chatMessageRepository->findRecentConversation($project, 8);
        $message = (new ChatMessage())
            ->setProject($project)
            ->setRole(ChatMessage::ROLE_USER)
            ->setContent((string) $request->request->get('message', ''));

        $violations = $validator->validate($message);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = $violation->getMessage();
            }

            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'errors' => $errors,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->addFlash('error', implode(' ', $errors));

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        $entityManager->persist($message);
        $entityManager->flush();

        if ($request->isXmlHttpRequest()) {
            $streamId = $chatStreamSessionStore->create($project, $user, $message);
            $history = $chatMessageRepository->paginateProjectConversation($project, 1, self::MESSAGES_PER_PAGE);

            return $this->json([
                'html' => $this->renderView('chat/_messages.html.twig', [
                    'messages' => [$message],
                ]),
                'messageId' => $message->getId(),
                'createdAt' => $message->getCreatedAt()->format(DATE_ATOM),
                'totalMessages' => $history['totalMessages'],
                'hasOlderMessages' => $history['hasOlderMessages'],
                'nextPage' => $history['nextPage'],
                'streamUrl' => $this->generateUrl('app_chat_stream', [
                    'projectId' => $project->getId(),
                    'streamId' => $streamId,
                ]),
            ], Response::HTTP_CREATED);
        }

        $assistantMessage = null;

        try {
            $generationResult = $chatCompletionService->generateAssistantReply(
                $project,
                $user,
                $message->getContent(),
                $priorConversation,
            );

            $assistantMessage = (new ChatMessage())
                ->setProject($project)
                ->setRole(ChatMessage::ROLE_ASSISTANT)
                ->setContent($generationResult->assistantContent());

            $entityManager->persist($assistantMessage);

            if ($generationResult->requiresConfirmation()) {
                $project->storePendingConfirmation($generationResult->pendingConfirmation() ?? []);
            } elseif ($generationResult->slidesChanged()) {
                $project->setSlides($generationResult->slides());
            }

            $entityManager->flush();

            if ($generationResult->slidesChanged()) {
                $projectVersioning->captureSnapshot($project);
            }
        } catch (\Throwable) {
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'errors' => ['Harmony n\'a pas pu generer de reponse IA pour le moment.'],
                ], Response::HTTP_BAD_GATEWAY);
            }

            $this->addFlash('error', 'Harmony n\'a pas pu generer de reponse IA pour le moment.');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        if ($request->isXmlHttpRequest()) {
            $history = $chatMessageRepository->paginateProjectConversation($project, 1, self::MESSAGES_PER_PAGE);

            return $this->json([
                'html' => $this->renderView('chat/_messages.html.twig', [
                    'messages' => [$message, $assistantMessage],
                ]),
                'messageId' => $message->getId(),
                'createdAt' => $message->getCreatedAt()->format(DATE_ATOM),
                'totalMessages' => $history['totalMessages'],
                'hasOlderMessages' => $history['hasOlderMessages'],
                'nextPage' => $history['nextPage'],
                'assistantMessageId' => $assistantMessage?->getId(),
            ], Response::HTTP_CREATED);
        }

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    #[Route('/confirmation', name: 'app_chat_resolve_confirmation', methods: ['POST'])]
    public function resolveConfirmation(
        int $projectId,
        Request $request,
        ProjectRepository $projectRepository,
        ChatEngine $chatEngine,
        ProjectVersioning $projectVersioning,
        EntityManagerInterface $entityManager,
    ): Response {
        $project = $this->findOwnedProjectOr404($projectId, $projectRepository);
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('chat_confirmation_'.$project->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        $pendingConfirmation = $project->getPendingConfirmation();
        if ($pendingConfirmation === null) {
            return $this->pendingConfirmationUnavailableResponse($request, $project);
        }

        $decision = trim((string) $request->request->get('decision', ''));
        if (!in_array($decision, ['confirm', 'cancel'], true)) {
            return $this->invalidConfirmationDecisionResponse($request, $project);
        }

        $slidesChanged = false;

        if ($decision === 'confirm') {
            $appliedActions = $chatEngine->applyConfirmedActions(
                $project,
                is_array($pendingConfirmation['proposed_actions'] ?? null) ? $pendingConfirmation['proposed_actions'] : [],
            );
            $slidesChanged = $appliedActions['changed'];
            if ($slidesChanged) {
                $project->setSlides($appliedActions['slides']);
            }

            $project->resolvePendingConfirmation('confirmed');
        } else {
            $project->resolvePendingConfirmation('cancelled');
        }

        $entityManager->flush();

        if ($slidesChanged) {
            $projectVersioning->captureSnapshot($project);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'decision' => $decision,
                'pendingConfirmation' => null,
                'previewHtml' => $this->renderView('project/_preview_list.html.twig', [
                    'slides' => $project->getSlides(),
                ]),
                'slidesCount' => $project->getSlidesCount(),
            ]);
        }

        $this->addFlash('success', $decision === 'confirm' ? 'Proposition appliquée.' : 'Proposition annulée.');

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    #[Route('/stream', name: 'app_chat_stream', methods: ['GET'])]
    public function streamGeneration(
        int $projectId,
        Request $request,
        ProjectRepository $projectRepository,
        ChatMessageRepository $chatMessageRepository,
        ChatStreamSessionStore $chatStreamSessionStore,
        ChatEngine $chatEngine,
        ProjectVersioning $projectVersioning,
        EntityManagerInterface $entityManager,
    ): StreamedResponse {
        $project = $this->findOwnedProjectOr404($projectId, $projectRepository);
        $user = $this->requireUser();
        $streamId = trim((string) $request->query->get('streamId', ''));
        if ($streamId === '') {
            throw $this->createNotFoundException();
        }

        $state = $chatStreamSessionStore->load($streamId);
        if (!is_array($state) || !$chatStreamSessionStore->isOwnedBy($state, $project, $user)) {
            throw $this->createNotFoundException();
        }

        $lastEventId = max(0, (int) $request->headers->get('Last-Event-ID', 0));

        return new StreamedResponse(function () use (
            $streamId,
            $project,
            $user,
            $lastEventId,
            $chatMessageRepository,
            $chatStreamSessionStore,
            $chatEngine,
            $projectVersioning,
            $entityManager,
        ): void {
            $this->prepareSseStream();
            echo 'retry: '.self::STREAM_RETRY_MILLISECONDS."\n\n";
            $this->flushSseBuffers();

            $lastSentEventId = $lastEventId;
            $state = $chatStreamSessionStore->load($streamId);
            if (!is_array($state)) {
                return;
            }

            $this->emitStoredEvents($chatStreamSessionStore, $state, $lastSentEventId);
            if (in_array((string) ($state['status'] ?? 'pending'), ['done', 'error'], true)) {
                return;
            }

            $lockHandle = $chatStreamSessionStore->acquireLock($streamId);
            if ($lockHandle === false) {
                $this->followStreamSession($streamId, $chatStreamSessionStore, $lastSentEventId);

                return;
            }

            try {
                $state = $chatStreamSessionStore->load($streamId);
                if (!is_array($state)) {
                    return;
                }

                $this->emitStoredEvents($chatStreamSessionStore, $state, $lastSentEventId);
                if (in_array((string) ($state['status'] ?? 'pending'), ['done', 'error'], true)) {
                    return;
                }

                $chatStreamSessionStore->markStatus($streamId, 'streaming');

                $userMessage = $chatMessageRepository->findProjectMessage($project, (int) ($state['userMessageId'] ?? 0));
                if (!$userMessage instanceof ChatMessage) {
                    throw new \RuntimeException('Chat stream user message not found.');
                }

                $priorConversation = $chatMessageRepository->findConversationBeforeMessage($project, $userMessage, 8);
                $generationResult = $chatEngine->streamAssistantReply(
                    $project,
                    $user,
                    $userMessage,
                    $priorConversation,
                    function (string $type, array $payload) use ($chatStreamSessionStore, $streamId, &$lastSentEventId): void {
                        $event = $chatStreamSessionStore->appendEvent(
                            $streamId,
                            $type,
                            $this->buildSlideEventPayload($payload['slide'] ?? []),
                        );
                        $this->emitSseEvent((int) $event['id'], $type, $event['payload']);
                        $lastSentEventId = (int) $event['id'];
                    },
                );

                $assistantMessage = (new ChatMessage())
                    ->setProject($project)
                    ->setRole(ChatMessage::ROLE_ASSISTANT)
                    ->setContent($generationResult->assistantContent());

                $entityManager->persist($assistantMessage);

                if ($generationResult->requiresConfirmation()) {
                    $project->storePendingConfirmation($generationResult->pendingConfirmation() ?? []);
                } elseif ($generationResult->slidesChanged()) {
                    $project->setSlides($generationResult->slides());
                }

                $entityManager->flush();

                if ($generationResult->slidesChanged()) {
                    $projectVersioning->captureSnapshot($project);
                }

                $chatStreamSessionStore->markStatus($streamId, 'done', $assistantMessage->getId());
                $history = $chatMessageRepository->paginateProjectConversation($project, 1, self::MESSAGES_PER_PAGE);
                $doneEvent = $chatStreamSessionStore->appendEvent($streamId, 'generation_done', [
                    'assistantHtml' => $this->renderView('chat/_messages.html.twig', [
                        'messages' => [$assistantMessage],
                    ]),
                    'assistantMessageId' => $assistantMessage->getId(),
                    'totalMessages' => $history['totalMessages'],
                    'hasOlderMessages' => $history['hasOlderMessages'],
                    'nextPage' => $history['nextPage'],
                    'slidesCount' => $project->getSlidesCount(),
                    'pendingConfirmation' => $this->buildPendingConfirmationPayload($generationResult->pendingConfirmation()),
                ]);

                $this->emitSseEvent((int) $doneEvent['id'], 'generation_done', $doneEvent['payload']);
            } catch (\Throwable) {
                $chatStreamSessionStore->markStatus($streamId, 'error');
                $errorEvent = $chatStreamSessionStore->appendEvent($streamId, 'error', [
                    'message' => 'Harmony n\'a pas pu terminer la generation en streaming.',
                ]);
                $this->emitSseEvent((int) $errorEvent['id'], 'error', $errorEvent['payload']);
            } finally {
                $chatStreamSessionStore->releaseLock($lockHandle);
            }
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function findOwnedProjectOr404(int $projectId, ProjectRepository $projectRepository): Project
    {
        $project = $projectRepository->findOwnedProject($projectId, $this->requireUser());
        if (!$project instanceof Project) {
            throw $this->createNotFoundException();
        }

        return $project;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function emitStoredEvents(ChatStreamSessionStore $chatStreamSessionStore, array $state, int &$lastSentEventId): void
    {
        foreach ($chatStreamSessionStore->eventsAfter($state, $lastSentEventId) as $event) {
            $eventId = (int) ($event['id'] ?? 0);
            $eventType = (string) ($event['type'] ?? 'message');
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

            $this->emitSseEvent($eventId, $eventType, $payload);
            $lastSentEventId = $eventId;
        }
    }

    private function followStreamSession(string $streamId, ChatStreamSessionStore $chatStreamSessionStore, int &$lastSentEventId): void
    {
        $startedAt = microtime(true);

        while (!connection_aborted()) {
            $state = $chatStreamSessionStore->load($streamId);
            if (!is_array($state)) {
                return;
            }

            $this->emitStoredEvents($chatStreamSessionStore, $state, $lastSentEventId);
            if (in_array((string) ($state['status'] ?? 'pending'), ['done', 'error'], true)) {
                return;
            }

            if ((microtime(true) - $startedAt) >= 30) {
                return;
            }

            usleep(self::STREAM_POLLING_MICROSECONDS);
        }
    }

    /**
     * @param array<string, mixed> $slide
     *
     * @return array<string, mixed>
     */
    private function buildSlideEventPayload(array $slide): array
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
            'body' => (string) ($slide['body'] ?? ''),
            'position' => (int) ($slide['position'] ?? 1),
        ];

        return [
            'slide' => $normalizedSlide,
            'html' => $this->renderView('project/_preview_slide.html.twig', [
                'slide' => $normalizedSlide,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function emitSseEvent(int $eventId, string $eventType, array $payload): void
    {
        echo 'id: '.$eventId."\n";
        echo 'event: '.$eventType."\n";

        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (explode("\n", $encodedPayload) as $line) {
            echo 'data: '.$line."\n";
        }

        echo "\n";
        $this->flushSseBuffers();
    }

    private function prepareSseStream(): void
    {
        ignore_user_abort(true);
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
    }

    private function flushSseBuffers(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }

    private function pendingConfirmationBlockedResponse(Request $request, Project $project): Response
    {
        $message = 'Confirmez ou annulez la proposition en attente avant d’envoyer un nouveau message.';

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'errors' => [$message],
            ], Response::HTTP_CONFLICT);
        }

        $this->addFlash('error', $message);

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    private function pendingConfirmationUnavailableResponse(Request $request, Project $project): Response
    {
        $message = 'Aucune proposition en attente à confirmer.';

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'errors' => [$message],
            ], Response::HTTP_CONFLICT);
        }

        $this->addFlash('error', $message);

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    private function invalidConfirmationDecisionResponse(Request $request, Project $project): Response
    {
        $message = 'La décision de confirmation est invalide.';

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'errors' => [$message],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('error', $message);

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
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
