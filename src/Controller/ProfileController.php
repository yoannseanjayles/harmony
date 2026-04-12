<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ProfileApiKeyType;
use App\Security\UserApiKeyManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;

final class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile', methods: ['GET', 'POST'])]
    public function index(Request $request, UserApiKeyManager $userApiKeyManager): Response
    {
        $user = $this->requireUser();
        $hasApiKey = $userApiKeyManager->hasUserApiKey($user);

        $form = $this->createForm(ProfileApiKeyType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $userApiKeyManager->rotateUserApiKey($user, (string) $form->get('apiKey')->getData());

            $this->addFlash('success', $hasApiKey ? 'profile.flash.rotated' : 'profile.flash.saved');

            return $this->redirectToRoute('app_profile');
        }

        $statusCode = $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('profile/index.html.twig', [
            'profileForm' => $form,
            'maskedApiKey' => $userApiKeyManager->maskedUserApiKey($user),
            'hasApiKey' => $hasApiKey,
        ], new Response(status: $statusCode));
    }

    #[Route('/profile/api-key/delete', name: 'app_profile_api_key_delete', methods: ['POST'])]
    public function deleteApiKey(Request $request, UserApiKeyManager $userApiKeyManager): Response
    {
        $user = $this->requireUser();

        if (!$this->isCsrfTokenValid('delete_api_key', (string) $request->request->get('_token'))) {
            throw new InvalidCsrfTokenException('Invalid CSRF token.');
        }

        $userApiKeyManager->removeUserApiKey($user);
        $this->addFlash('success', 'profile.flash.deleted');

        return $this->redirectToRoute('app_profile');
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
