<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class LoginRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.login_by_ip')]
        private readonly RateLimiterFactory $loginLimiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 30],
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getMethod() !== Request::METHOD_POST || $request->getPathInfo() !== '/login') {
            return;
        }

        $this->loginLimiter
            ->create($this->resolveKey($request))
            ->consume(1)
            ->ensureAccepted();
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->getPathInfo() !== '/login') {
            return;
        }

        $this->loginLimiter->create($this->resolveKey($request))->reset();
    }

    private function resolveKey(Request $request): string
    {
        return $request->getClientIp() ?: 'anonymous';
    }
}
