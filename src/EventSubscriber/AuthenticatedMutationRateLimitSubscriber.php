<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class AuthenticatedMutationRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        #[Autowire(service: 'limiter.ai_by_user')]
        private readonly RateLimiterFactory $aiLimiter,
        #[Autowire(service: 'limiter.export_by_user')]
        private readonly RateLimiterFactory $exportLimiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority must be < 8 (the security firewall) so getUser() returns the
            // authenticated user; higher-priority subscribers run before authentication.
            KernelEvents::REQUEST => ['onKernelRequest', 7],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!\in_array($request->getMethod(), [Request::METHOD_POST, Request::METHOD_PUT, Request::METHOD_PATCH, Request::METHOD_DELETE], true)) {
            return;
        }

        $user = $this->security->getUser();
        if ($user === null || !method_exists($user, 'getUserIdentifier')) {
            return;
        }

        $path = $request->getPathInfo();
        if (str_starts_with($path, '/ai')) {
            $this->aiLimiter->create($user->getUserIdentifier())->consume(1)->ensureAccepted();

            return;
        }

        if (str_starts_with($path, '/export')) {
            $this->exportLimiter->create($user->getUserIdentifier())->consume(1)->ensureAccepted();
        }
    }
}
