<?php

namespace App\Tests\Functional;

use App\Entity\ChatMessage;
use App\Entity\Project;
use App\Entity\User;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProjectChatStreamingTest extends FunctionalTestCase
{
    public function testAjaxChatMessageStreamsFiveSlidesAndSupportsReplay(): void
    {
        $user = $this->createUser('chat-stream-owner@harmony.test');
        $project = $this->createProject($user, 'Projet streaming SSE');

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());
        $token = $crawler
            ->filter(sprintf('form[action="/projects/%d/chat/send-message"] input[name="_token"]', $project->getId()))
            ->attr('value');

        self::assertNotFalse($token);

        $this->client->xmlHttpRequest('POST', '/projects/'.$project->getId().'/chat/send-message', [
            '_token' => $token,
            'message' => 'Genere 5 slides pour le lancement du produit Harmony',
        ]);

        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Genere 5 slides', $payload['html']);
        self::assertStringContainsString('/projects/'.$project->getId().'/chat/stream', $payload['streamUrl']);

        $this->client->request('GET', $payload['streamUrl'], server: [
            'HTTP_ACCEPT' => 'text/event-stream',
            'HTTP_CACHE_CONTROL' => 'no-cache',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/event-stream; charset=UTF-8');
        self::assertResponseHeaderSame('x-accel-buffering', 'no');

        $streamContent = $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString('retry: 1000', $streamContent);
        self::assertSame(5, preg_match_all('/^event: slide_added$/m', $streamContent));
        self::assertSame(1, preg_match_all('/^event: generation_done$/m', $streamContent));
        self::assertStringContainsString('Vision du lancement', $streamContent);
        self::assertStringContainsString('Prochaines etapes', $streamContent);

        $this->entityManager->clear();

        /** @var Project|null $reloadedProject */
        $reloadedProject = $this->entityManager->getRepository(Project::class)->find($project->getId());
        self::assertInstanceOf(Project::class, $reloadedProject);
        self::assertSame(5, $reloadedProject->getSlidesCount());

        /** @var list<ChatMessage> $messages */
        $messages = $this->entityManager->getRepository(ChatMessage::class)->findBy([
            'project' => $reloadedProject,
        ], [
            'id' => 'ASC',
        ]);

        self::assertCount(2, $messages);
        self::assertSame(ChatMessage::ROLE_USER, $messages[0]->getRole());
        self::assertSame(ChatMessage::ROLE_ASSISTANT, $messages[1]->getRole());
        self::assertSame(
            'Reponse Harmony mock (OpenAI): Genere 5 slides pour le lancement du produit Harmony',
            $messages[1]->getContent(),
        );

        $this->client->request('GET', $payload['streamUrl'], server: [
            'HTTP_ACCEPT' => 'text/event-stream',
            'HTTP_LAST_EVENT_ID' => '5',
        ]);

        self::assertResponseIsSuccessful();

        $replayContent = $this->client->getInternalResponse()->getContent();
        self::assertStringNotContainsString("id: 1\n", $replayContent);
        self::assertStringNotContainsString("id: 5\n", $replayContent);
        self::assertStringContainsString("id: 6\n", $replayContent);
        self::assertStringContainsString('event: generation_done', $replayContent);
    }

    private function createUser(string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword(
                static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword(
                    new User(),
                    'ValidPassword123',
                ),
            );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createProject(User $user, string $title): Project
    {
        $project = (new Project())
            ->setTitle($title)
            ->setProvider('openai')
            ->setModel('gpt-4.1')
            ->setStatus(Project::STATUS_ACTIVE)
            ->setSlides([])
            ->setUser($user);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }
}
