<?php

namespace App\Tests\Functional;

use App\Entity\ChatMessage;
use App\Entity\Project;
use App\Entity\User;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProjectChatTest extends FunctionalTestCase
{
    public function testProjectShowDisplaysChatHistorySupportsSendingAndLoadsOlderMessages(): void
    {
        $user = $this->createUser('chat-owner@harmony.test');
        $project = $this->createProject($user, 'Projet conversation');

        for ($index = 1; $index <= 11; ++$index) {
            $this->createChatMessage(
                $project,
                $index % 2 === 0 ? ChatMessage::ROLE_ASSISTANT : ChatMessage::ROLE_USER,
                sprintf('Message %02d', $index),
                sprintf('2026-04-12 08:%02d:00', $index),
            );
        }

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Panneau de chat');
        self::assertSelectorTextContains('body', 'Message 11');
        self::assertStringNotContainsString('Message 01', (string) $this->client->getResponse()->getContent());

        $sendForm = $crawler->filter(sprintf('form[action="/projects/%d/chat/send-message"]', $project->getId()))->form([
            'message' => "Nouvelle demande\nsur deux lignes",
        ]);
        $this->client->submit($sendForm);

        self::assertResponseRedirects('/projects/'.$project->getId());

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Nouvelle demande');
        self::assertSelectorTextContains('body', 'Reponse Harmony mock (OpenAI): Nouvelle demande');
        self::assertSame(13, $this->countProjectMessages($project->getId()));

        $this->client->request('GET', '/projects/'.$project->getId().'/chat/history?page=2');
        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $payload['page']);
        self::assertSame(2, $payload['totalPages']);
        self::assertFalse($payload['hasOlderMessages']);
        self::assertNull($payload['nextPage']);
        self::assertStringContainsString('Message 01', $payload['html']);
        self::assertStringContainsString('Message 02', $payload['html']);
        self::assertStringContainsString('Message 03', $payload['html']);
        self::assertStringNotContainsString('Nouvelle demande', $payload['html']);
    }

    public function testUserCannotAccessAnotherUsersChatRoutes(): void
    {
        $owner = $this->createUser('chat-owner-private@harmony.test');
        $intruder = $this->createUser('chat-intruder@harmony.test');
        $project = $this->createProject($owner, 'Projet prive chat');
        $this->createChatMessage($project, ChatMessage::ROLE_ASSISTANT, 'Message prive', '2026-04-12 08:00:00');

        $this->client->loginUser($intruder);

        $this->client->request('GET', '/projects/'.$project->getId().'/chat/history');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/projects/'.$project->getId().'/chat/send-message', [
            '_token' => 'invalid',
            'message' => 'Intrusion',
        ]);
        self::assertResponseStatusCodeSame(404);
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
            ->setSlides([
                ['id' => 'slide-1', 'title' => 'Introduction'],
                ['id' => 'slide-2', 'title' => 'Synthese'],
            ])
            ->setUser($user);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function createChatMessage(Project $project, string $role, string $content, string $createdAt): ChatMessage
    {
        $managedProject = $project->getId() !== null
            ? $this->entityManager->getReference(Project::class, $project->getId())
            : $project;

        $message = (new ChatMessage())
            ->setProject($managedProject)
            ->setRole($role)
            ->setContent($content);

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $createdAtValue = new \DateTimeImmutable($createdAt);
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE chat_message SET created_at = :createdAt WHERE id = :id',
            [
                'createdAt' => $createdAtValue->format('Y-m-d H:i:s'),
                'id' => $message->getId(),
            ],
        );
        $this->setDateTimeProperty($message, 'createdAt', $createdAtValue);

        return $message;
    }

    private function countProjectMessages(int $projectId): int
    {
        $project = $this->entityManager->getReference(Project::class, $projectId);

        return $this->entityManager->getRepository(ChatMessage::class)->count([
            'project' => $project,
        ]);
    }

    private function setDateTimeProperty(object $entity, string $propertyName, \DateTimeImmutable $value): void
    {
        $property = new \ReflectionProperty($entity::class, $propertyName);
        $property->setAccessible(true);
        $property->setValue($entity, $value);
    }
}
