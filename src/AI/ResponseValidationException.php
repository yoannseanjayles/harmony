<?php

namespace App\AI;

final class ResponseValidationException extends \RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        private readonly array $errors,
        private readonly string $rawContent,
        string $message = 'AI response validation failed.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function rawContent(): string
    {
        return $this->rawContent;
    }
}
