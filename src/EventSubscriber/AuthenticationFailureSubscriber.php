<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\SecurityLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class AuthenticationFailureSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly SecurityLogger $securityLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginFailureEvent::class => 'onLoginFailure',
        ];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->getPathInfo() !== '/login') {
            return;
        }

        $identifier = trim((string) $request->request->get('email', ''));
        $user = $identifier !== ''
            ? $this->userRepository->findOneBy(['email' => mb_strtolower($identifier)])
            : null;

        $this->securityLogger->logAuthenticationFailure(
            $identifier !== '' ? $identifier : null,
            $user instanceof User ? $user->getId() : null,
            $request->getClientIp(),
            $user instanceof User ? 'bad_password' : 'unknown_account',
        );
    }
}
