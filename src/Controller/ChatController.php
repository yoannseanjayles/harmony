<?php

namespace App\Controller;

use App\AI\ChatCompletionService;
use App\Entity\ChatMessage;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ChatMessageRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/projects/{projectId}/chat', requirements: ['projectId' => '\d+'])]
final class ChatController extends AbstractController
{
    private const MESSAGES_PER_PAGE = 10;

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
        ChatCompletionService $chatCompletionService,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
    ): Response {
        $project = $this->findOwnedProjectOr404($projectId, $projectRepository);
        $user = $this->requireUser();
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('chat_send_'.$project->getId(), $token)) {
            throw $this->createAccessDeniedException();
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

        $assistantMessage = null;

        try {
            $providerResponse = $chatCompletionService->generateAssistantReply(
                $project,
                $user,
                $message->getContent(),
                $priorConversation,
            );

            $assistantMessage = (new ChatMessage())
                ->setProject($project)
                ->setRole(ChatMessage::ROLE_ASSISTANT)
                ->setContent($providerResponse->content());

            $entityManager->persist($assistantMessage);
            $entityManager->flush();
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
}
