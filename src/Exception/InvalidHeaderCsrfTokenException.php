<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class InvalidHeaderCsrfTokenException extends AccessDeniedHttpException
{
    public function __construct(
        private readonly string $tokenId,
        string $message = 'Invalid CSRF token.',
    ) {
        parent::__construct($message);
    }

    public function getTokenId(): string
    {
        return $this->tokenId;
    }
}
