<?php

namespace App\EventSubscriber;

use App\Exception\InvalidHeaderCsrfTokenException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ApiCsrfProtectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isProtectedMutation($request)) {
            return;
        }

        $tokenId = (string) ($request->attributes->get('_csrf_header_token_id') ?: 'api_mutation');
        $tokenValue = (string) $request->headers->get('X-CSRF-Token', '');

        if ($tokenValue === '' || !$this->csrfTokenManager->isTokenValid(new CsrfToken($tokenId, $tokenValue))) {
            throw new InvalidHeaderCsrfTokenException($tokenId);
        }
    }

    private function isProtectedMutation(Request $request): bool
    {
        if (!\in_array($request->getMethod(), [Request::METHOD_POST, Request::METHOD_PUT, Request::METHOD_PATCH, Request::METHOD_DELETE], true)) {
            return false;
        }

        $path = $request->getPathInfo();

        return str_starts_with($path, '/api')
            || str_starts_with($path, '/ai')
            || str_starts_with($path, '/export');
    }
}
