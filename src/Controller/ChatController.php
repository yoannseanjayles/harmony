<?php

namespace App\Controller;

use App\AI\ChatEngine;
use App\AI\EmptyAIResponseException;
use App\Chat\ChatGenerationOrchestrator;
use App\Chat\Message\GenerateChatReplyMessage;
use App\Entity\ChatMessage;
use App\Entity\Project;
use App\Entity\User;
use App\Chat\ChatStreamSessionStore;
use App\Project\ProjectVersioning;
use App\Repository\ChatMessageRepository;
use App\Repository\ProjectRepository;
use App\Slide\SlideBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
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
        ChatGenerationOrchestrator $orchestrator,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        MessageBusInterface $messageBus,
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

            $messageBus->dispatch(new GenerateChatReplyMessage(
                $project->getId(),
                $user->getId(),
                $message->getId(),
                $streamId,
            ));

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
            $result = $orchestrator->generateSync(
                $project,
                $user,
                $message,
                $priorConversation,
            );

            $assistantMessage = $result->assistantMessage();
        } catch (\Throwable $e) {
            $isEmptyResponse = $e instanceof EmptyAIResponseException;

            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'errors' => ['Harmony n\'a pas pu generer de reponse IA pour le moment.'],
                    'retryAvailable' => $isEmptyResponse,
                    'userMessageId' => $message->getId(),
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

    /**
     * Creates a new stream session for an existing user message, enabling the
     * manual retry button (T123) after an AI generation failure.
     */
    #[Route('/retry', name: 'app_chat_retry', methods: ['POST'])]
    public function retryGeneration(
        int $projectId,
        Request $request,
        ProjectRepository $projectRepository,
        ChatMessageRepository $chatMessageRepository,
        ChatStreamSessionStore $chatStreamSessionStore,
        MessageBusInterface $messageBus,
    ): JsonResponse {
        $project = $this->findOwnedProjectOr404($projectId, $projectRepository);
        $user = $this->requireUser();
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('chat_send_'.$project->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        $userMessageId = $request->request->getInt('userMessageId');
        $userMessage = $chatMessageRepository->findProjectMessage($project, $userMessageId);
        if (!$userMessage instanceof ChatMessage || !$userMessage->isUser()) {
            return $this->json([
                'errors' => ['Message utilisateur introuvable pour la relance.'],
            ], Response::HTTP_NOT_FOUND);
        }

        $streamId = $chatStreamSessionStore->create($project, $user, $userMessage);

        $messageBus->dispatch(new GenerateChatReplyMessage(
            $project->getId(),
            $user->getId(),
            $userMessage->getId(),
            $streamId,
        ));

        return $this->json([
            'streamUrl' => $this->generateUrl('app_chat_stream', [
                'projectId' => $project->getId(),
                'streamId' => $streamId,
            ]),
        ], Response::HTTP_CREATED);
    }

    #[Route('/confirmation', name: 'app_chat_resolve_confirmation', methods: ['POST'])]
    public function resolveConfirmation(
        int $projectId,
        Request $request,
        ProjectRepository $projectRepository,
        ChatEngine $chatEngine,
        ProjectVersioning $projectVersioning,
        EntityManagerInterface $entityManager,
        SlideBuilder $slideBuilder,
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
            $slidesHtmlMap = [];
            foreach ($project->getSlides() as $slide) {
                try {
                    $slidesHtmlMap[(string) $slide->getId()] = $slideBuilder->buildSlide($slide);
                } catch (\Throwable) {
                    // skip unrenderable slides
                }
            }

            return $this->json([
                'decision' => $decision,
                'pendingConfirmation' => null,
                'previewHtml' => $this->renderView('project/_preview_list.html.twig', [
                    'slides' => $project->getSlides(),
                ]),
                'slidesHtmlMap' => $slidesHtmlMap,
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
        ChatStreamSessionStore $chatStreamSessionStore,
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
            $lastEventId,
            $chatStreamSessionStore,
        ): void {
            $this->prepareSseStream();
            echo 'retry: '.self::STREAM_RETRY_MILLISECONDS."\n\n";
            $this->flushSseBuffers();

            $lastSentEventId = $lastEventId;

            // Poll the DB-backed stream store for events published by the Messenger handler.
            $this->followStreamSession($streamId, $chatStreamSessionStore, $lastSentEventId);
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
}
