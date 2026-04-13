<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * Returns a JSON 403 response for AJAX requests instead of rendering
 * an HTML error page or redirecting.
 */
final class AjaxAwareAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        if ($request->isXmlHttpRequest() || $request->getPreferredFormat() === 'json') {
            return new JsonResponse([
                'errors' => ['Accès refusé. Veuillez vérifier vos permissions ou vous reconnecter.'],
                'redirect' => $this->urlGenerator->generate('app_login'),
            ], Response::HTTP_FORBIDDEN);
        }

        return null; // Let the default handler (redirect) take over for non-AJAX requests.
    }
}
