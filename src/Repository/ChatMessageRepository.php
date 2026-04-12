<?php

namespace App\Repository;

use App\Entity\ChatMessage;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatMessage>
 */
class ChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /**
     * @return array{
     *     messages: list<ChatMessage>,
     *     page: int,
     *     totalPages: int,
     *     totalMessages: int,
     *     hasOlderMessages: bool,
     *     nextPage: int|null
     * }
     */
    public function paginateProjectConversation(Project $project, int $page = 1, int $perPage = 10): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $totalMessages = $this->count(['project' => $project]);
        $totalPages = max(1, (int) ceil($totalMessages / $perPage));
        $page = min($page, $totalPages);

        /** @var list<ChatMessage> $messages */
        $messages = $this->createQueryBuilder('message')
            ->andWhere('message.project = :project')
            ->setParameter('project', $project)
            ->orderBy('message.createdAt', 'DESC')
            ->addOrderBy('message.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return [
            'messages' => array_reverse($messages),
            'page' => $page,
            'totalPages' => $totalPages,
            'totalMessages' => $totalMessages,
            'hasOlderMessages' => $page < $totalPages,
            'nextPage' => $page < $totalPages ? $page + 1 : null,
        ];
    }

    /**
     * @return list<ChatMessage>
     */
    public function findRecentConversation(Project $project, int $limit = 8): array
    {
        /** @var list<ChatMessage> $messages */
        $messages = $this->createQueryBuilder('message')
            ->andWhere('message.project = :project')
            ->setParameter('project', $project)
            ->orderBy('message.createdAt', 'DESC')
            ->addOrderBy('message.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return array_reverse($messages);
    }

    public function findProjectMessage(Project $project, int $messageId): ?ChatMessage
    {
        $message = $this->findOneBy([
            'id' => $messageId,
            'project' => $project,
        ]);

        return $message instanceof ChatMessage ? $message : null;
    }

    /**
     * @return list<ChatMessage>
     */
    public function findConversationBeforeMessage(Project $project, ChatMessage $message, int $limit = 8): array
    {
        /** @var list<ChatMessage> $messages */
        $messages = $this->createQueryBuilder('chatMessage')
            ->andWhere('chatMessage.project = :project')
            ->andWhere('(chatMessage.createdAt < :createdAt OR (chatMessage.createdAt = :createdAt AND chatMessage.id < :messageId))')
            ->setParameter('project', $project)
            ->setParameter('createdAt', $message->getCreatedAt())
            ->setParameter('messageId', $message->getId())
            ->orderBy('chatMessage.createdAt', 'DESC')
            ->addOrderBy('chatMessage.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return array_reverse($messages);
    }

    public function countByProject(Project $project): int
    {
        return $this->count([
            'project' => $project,
        ]);
    }
}
