<?php

namespace App\Tests\Functional;

use App\Entity\Project;
use App\Entity\User;
use App\Tests\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProjectChatConfirmationTest extends FunctionalTestCase
{
    public function testStreamingProposalCanBeConfirmedAndApplied(): void
    {
        $user = $this->createUser('chat-confirm-owner@harmony.test');
        $project = $this->createProject($user, 'Projet confirmation');

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());
        $sendToken = $crawler
            ->filter(sprintf('form[action="/projects/%d/chat/send-message"] input[name="_token"]', $project->getId()))
            ->attr('value');
        $confirmationToken = $crawler
            ->filter(sprintf('form[action="/projects/%d/chat/confirmation"] input[name="_token"]', $project->getId()))
            ->attr('value');

        self::assertNotFalse($sendToken);
        self::assertNotFalse($confirmationToken);

        $this->client->xmlHttpRequest('POST', '/projects/'.$project->getId().'/chat/send-message', [
            '_token' => $sendToken,
            'message' => 'Reorganise les slides pour commencer par la synthese',
        ]);

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->client->request('GET', $payload['streamUrl'], server: [
            'HTTP_ACCEPT' => 'text/event-stream',
            'HTTP_CACHE_CONTROL' => 'no-cache',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('event: generation_done', $this->client->getInternalResponse()->getContent());
        self::assertStringContainsString('"pendingConfirmation"', $this->client->getInternalResponse()->getContent());

        $this->entityManager->clear();
        /** @var Project|null $reloadedProject */
        $reloadedProject = $this->entityManager->getRepository(Project::class)->find($project->getId());
        self::assertInstanceOf(Project::class, $reloadedProject);
        self::assertSame(['slide-1', 'slide-2', 'slide-3'], array_column($reloadedProject->getSlides(), 'id'));
        self::assertTrue($reloadedProject->hasPendingConfirmation());

        $this->client->xmlHttpRequest('POST', '/projects/'.$project->getId().'/chat/send-message', [
            '_token' => $sendToken,
            'message' => 'Autre demande',
        ]);

        self::assertResponseStatusCodeSame(409);

        $this->client->xmlHttpRequest('POST', '/projects/'.$project->getId().'/chat/confirmation', [
            '_token' => $confirmationToken,
            'decision' => 'confirm',
        ]);

        self::assertResponseIsSuccessful();
        $confirmationPayload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('confirm', $confirmationPayload['decision']);
        self::assertNull($confirmationPayload['pendingConfirmation']);

        $this->entityManager->clear();
        $confirmedProject = $this->entityManager->getRepository(Project::class)->find($project->getId());
        self::assertInstanceOf(Project::class, $confirmedProject);
        self::assertSame(['slide-2', 'slide-1', 'slide-3'], array_column($confirmedProject->getSlides(), 'id'));
        self::assertFalse($confirmedProject->hasPendingConfirmation());
        self::assertSame('confirmed', $confirmedProject->getMetadata()['chat']['last_confirmation']['decision'] ?? null);
    }

    public function testStreamingProposalCanBeCancelledWithoutPersistingChanges(): void
    {
        $user = $this->createUser('chat-cancel-owner@harmony.test');
        $project = $this->createProject($user, 'Projet annulation');

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/projects/'.$project->getId());
        $sendToken = $crawler
            ->filter(sprintf('form[action="/projects/%d/chat/send-message"] input[name="_token"]', $project->getId()))
            ->attr('value');
        $confirmationToken = $crawler
            ->filter(sprintf('form[action="/projects/%d/chat/confirmation"] input[name="_token"]', $project->getId()))
            ->attr('value');

        self::assertNotFalse($sendToken);
        self::assertNotFalse($confirmationToken);

        $this->client->xmlHttpRequest('POST', '/projects/'.$project->getId().'/chat/send-message', [
            '_token' => $sendToken,
            'message' => 'Reordonne les slides avant de continuer',
        ]);

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->client->request('GET', $payload['streamUrl'], server: [
            'HTTP_ACCEPT' => 'text/event-stream',
            'HTTP_CACHE_CONTROL' => 'no-cache',
        ]);

        self::assertResponseIsSuccessful();

        $this->client->xmlHttpRequest('POST', '/projects/'.$project->getId().'/chat/confirmation', [
            '_token' => $confirmationToken,
            'decision' => 'cancel',
        ]);

        self::assertResponseIsSuccessful();
        $confirmationPayload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('cancel', $confirmationPayload['decision']);
        self::assertNull($confirmationPayload['pendingConfirmation']);

        $this->entityManager->clear();
        $cancelledProject = $this->entityManager->getRepository(Project::class)->find($project->getId());
        self::assertInstanceOf(Project::class, $cancelledProject);
        self::assertSame(['slide-1', 'slide-2', 'slide-3'], array_column($cancelledProject->getSlides(), 'id'));
        self::assertFalse($cancelledProject->hasPendingConfirmation());
        self::assertSame('cancelled', $cancelledProject->getMetadata()['chat']['last_confirmation']['decision'] ?? null);
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
                ['id' => 'slide-3', 'title' => 'Conclusion'],
            ])
            ->setUser($user);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }
}
