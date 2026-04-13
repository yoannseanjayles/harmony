<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Exception\InvalidHeaderCsrfTokenException;
use App\Ops\OpsLogger;
use App\Security\SecurityLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\Exception\RateLimitExceededException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Twig\Environment;

final class SecurityExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Environment $twig,
        private readonly SecurityLogger $securityLogger,
        private readonly Security $security,
        private readonly OpsLogger $opsLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof RateLimitExceededException) {
            $this->logRateLimitEvent($event->getRequest());
            $event->setResponse($this->createRateLimitedResponse($event->getRequest(), $throwable));

            return;
        }

        if ($throwable instanceof InvalidHeaderCsrfTokenException || $throwable instanceof InvalidCsrfTokenException) {
            $request = $event->getRequest();
            $user = $this->security->getUser();

            $this->securityLogger->logCsrfInvalid(
                $user instanceof User ? $user->getId() : null,
                $request->getClientIp(),
                [
                'path' => $request->getPathInfo(),
                'method' => $request->getMethod(),
                'userAgent' => $request->headers->get('User-Agent'),
                ],
            );

            $event->setResponse($this->createInvalidCsrfResponse($request));
        }
    }

    private function createRateLimitedResponse(Request $request, RateLimitExceededException $exception): Response
    {
        $retryAfter = max(1, $exception->getRetryAfter()->getTimestamp() - time());
        $headers = [
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => (string) $exception->getLimit(),
            'X-RateLimit-Remaining' => (string) $exception->getRemainingTokens(),
        ];

        if ($this->wantsJson($request)) {
            return new JsonResponse([
                'message' => 'Trop de requetes. Reessayez plus tard.',
                'retryAfter' => $retryAfter,
            ], Response::HTTP_TOO_MANY_REQUESTS, $headers);
        }

        return new Response(
            $this->twig->render('security/too_many_requests.html.twig', [
                'retryAfter' => $retryAfter,
            ]),
            Response::HTTP_TOO_MANY_REQUESTS,
            $headers,
        );
    }

    private function createInvalidCsrfResponse(Request $request): Response
    {
        if ($this->wantsJson($request)) {
            return new JsonResponse([
                'message' => 'Le jeton CSRF est invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        return new Response(
            $this->twig->render('security/csrf_invalid.html.twig'),
            Response::HTTP_FORBIDDEN,
        );
    }

    private function wantsJson(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api')
            || str_starts_with($request->getPathInfo(), '/ai')
            || str_starts_with($request->getPathInfo(), '/export')
            || $request->isXmlHttpRequest();
    }

    private function logRateLimitEvent(Request $request): void
    {
        if (!str_starts_with($request->getPathInfo(), '/ai')) {
            return;
        }

        $user = $this->security->getUser();
        $userId = $user instanceof User ? $user->getId() : null;
        $provider = (string) $request->request->get('provider', 'openai');
        $model = (string) $request->request->get('model', 'default');

        $this->securityLogger->logAiQuotaExceeded(
            $userId,
            $request->getClientIp(),
            $provider,
            $model,
        );

        $this->opsLogger->logQuotaExceeded(
            $userId,
            $provider,
            new \DateTimeImmutable(),
        );
    }
}
