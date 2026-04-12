<?php

namespace App\AI;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ResponseValidator
{
    public function __construct(
        private readonly ResponseSchema $responseSchema,
        #[Autowire(service: 'monolog.logger.ai')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function validate(string $rawContent): ValidatedResponse
    {
        [$payload, $locallyRepaired] = $this->decodePayload($rawContent);
        $errors = $this->collectErrors($payload);

        if ($errors !== []) {
            $this->logger->warning('ai_response_validation_failed', [
                'errors' => $errors,
                'payload' => $rawContent,
                'schema' => $this->responseSchema->definition(),
            ]);

            throw new ResponseValidationException($errors, $rawContent);
        }

        return new ValidatedResponse($this->normalizePayload($payload), $locallyRepaired);
    }

    /**
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function decodePayload(string $rawContent): array
    {
        $decoded = $this->decodeJson($rawContent);
        if (is_array($decoded)) {
            return [$decoded, false];
        }

        $repairedContent = $this->repairJson($rawContent);
        if ($repairedContent !== null) {
            $decoded = $this->decodeJson($repairedContent);
            if (is_array($decoded)) {
                return [$decoded, true];
            }
        }

        $errors = ['The AI output is not valid JSON and could not be repaired locally.'];
        $this->logger->warning('ai_response_validation_failed', [
            'errors' => $errors,
            'payload' => $rawContent,
            'schema' => $this->responseSchema->definition(),
        ]);

        throw new ResponseValidationException($errors, $rawContent);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $content): ?array
    {
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function repairJson(string $rawContent): ?string
    {
        $candidate = trim($rawContent);
        $candidate = preg_replace('/^\xEF\xBB\xBF/', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/^```(?:json)?\s*/i', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\s*```$/', '', $candidate) ?? $candidate;

        $firstBrace = strpos($candidate, '{');
        $lastBrace = strrpos($candidate, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $candidate = substr($candidate, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        $candidate = preg_replace('/,\s*([\]}])/m', '$1', $candidate) ?? $candidate;
        $candidate = trim($candidate);

        return $candidate !== '' ? $candidate : null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function collectErrors(array $payload): array
    {
        $errors = [];

        if (!array_key_exists('assistant_message', $payload) || !is_string($payload['assistant_message'])) {
            $errors[] = 'assistant_message must be a string.';
        } else {
            $assistantLength = mb_strlen(trim($payload['assistant_message']));
            if ($assistantLength < 1 || $assistantLength > 2000) {
                $errors[] = 'assistant_message must contain between 1 and 2000 characters.';
            }
        }

        if (!array_key_exists('actions', $payload) || !is_array($payload['actions'])) {
            $errors[] = 'actions must be an array.';

            return $errors;
        }

        $actions = $payload['actions'];
        if (count($actions) > 12) {
            $errors[] = 'actions cannot contain more than 12 entries.';
        }

        foreach ($actions as $index => $action) {
            $path = sprintf('actions[%d]', $index);
            if (!is_array($action)) {
                $errors[] = $path.' must be an object.';
                continue;
            }

            $actionName = $action['action'] ?? null;
            if (!is_string($actionName) || !in_array($actionName, $this->responseSchema->supportedActions(), true)) {
                $errors[] = $path.'.action is invalid.';
                continue;
            }

            switch ($actionName) {
                case 'add_slide':
                    $this->validateAddSlideAction($action, $path, $errors);
                    break;
                case 'update_slide':
                    $this->validateUpdateSlideAction($action, $path, $errors);
                    break;
                case 'remove_slide':
                    $this->validateRemoveSlideAction($action, $path, $errors);
                    break;
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $action
     * @param list<string> $errors
     */
    private function validateAddSlideAction(array $action, string $path, array &$errors): void
    {
        if (isset($action['position']) && (!$this->isIntLike($action['position']) || (int) $action['position'] < 1 || (int) $action['position'] > 50)) {
            $errors[] = $path.'.position must be an integer between 1 and 50.';
        }

        $slide = $action['slide'] ?? null;
        if (!is_array($slide)) {
            $errors[] = $path.'.slide must be an object.';

            return;
        }

        $this->validateSlideDefinition($slide, $path.'.slide', $errors, requireFullDefinition: true);
    }

    /**
     * @param array<string, mixed> $action
     * @param list<string> $errors
     */
    private function validateUpdateSlideAction(array $action, string $path, array &$errors): void
    {
        $slideId = $action['slide_id'] ?? null;
        if (!$this->isNonEmptyString($slideId, 1, 80)) {
            $errors[] = $path.'.slide_id must be a non-empty string with max 80 characters.';
        }

        $changes = $action['changes'] ?? null;
        if (!is_array($changes)) {
            $errors[] = $path.'.changes must be an object.';

            return;
        }

        if ($changes === []) {
            $errors[] = $path.'.changes must contain at least one field.';

            return;
        }

        $this->validateSlideDefinition($changes, $path.'.changes', $errors, requireFullDefinition: false);

        if (isset($changes['position']) && (!$this->isIntLike($changes['position']) || (int) $changes['position'] < 1 || (int) $changes['position'] > 50)) {
            $errors[] = $path.'.changes.position must be an integer between 1 and 50.';
        }
    }

    /**
     * @param array<string, mixed> $action
     * @param list<string> $errors
     */
    private function validateRemoveSlideAction(array $action, string $path, array &$errors): void
    {
        $slideId = $action['slide_id'] ?? null;
        if (!$this->isNonEmptyString($slideId, 1, 80)) {
            $errors[] = $path.'.slide_id must be a non-empty string with max 80 characters.';
        }
    }

    /**
     * @param array<string, mixed> $slide
     * @param list<string> $errors
     */
    private function validateSlideDefinition(array $slide, string $path, array &$errors, bool $requireFullDefinition): void
    {
        if ($requireFullDefinition && !$this->isNonEmptyString($slide['title'] ?? null, 3, 120)) {
            $errors[] = $path.'.title must contain between 3 and 120 characters.';
        } elseif (array_key_exists('title', $slide) && !$this->isNonEmptyString($slide['title'], 3, 120)) {
            $errors[] = $path.'.title must contain between 3 and 120 characters.';
        }

        if ($requireFullDefinition && !is_string($slide['type'] ?? null)) {
            $errors[] = $path.'.type must be provided.';
        }

        if (array_key_exists('type', $slide)) {
            $type = $slide['type'];
            if (!is_string($type) || !in_array($type, $this->responseSchema->supportedSlideTypes(), true)) {
                $errors[] = $path.'.type is not supported.';
            }
        }

        if (array_key_exists('id', $slide) && !$this->isNonEmptyString($slide['id'], 1, 80)) {
            $errors[] = $path.'.id must be a non-empty string with max 80 characters.';
        }

        if (array_key_exists('subtitle', $slide) && !$this->isNullableStringWithinLength($slide['subtitle'], 140)) {
            $errors[] = $path.'.subtitle must be a string with max 140 characters.';
        }

        if (array_key_exists('body', $slide) && !$this->isNullableStringWithinLength($slide['body'], 600)) {
            $errors[] = $path.'.body must be a string with max 600 characters.';
        }

        if (array_key_exists('notes', $slide) && !$this->isNullableStringWithinLength($slide['notes'], 400)) {
            $errors[] = $path.'.notes must be a string with max 400 characters.';
        }

        if ($requireFullDefinition && !array_key_exists('items', $slide) && ($slide['type'] ?? null) === 'bullet_list') {
            $errors[] = $path.'.items must be provided for bullet_list slides.';
        }

        if (array_key_exists('items', $slide) && !$this->validateItems($slide['items'])) {
            $errors[] = $path.'.items must contain between 1 and 6 strings, each between 1 and 140 characters.';
        }
    }

    private function validateItems(mixed $items): bool
    {
        if (!is_array($items) || $items === [] || count($items) > 6) {
            return false;
        }

        foreach ($items as $item) {
            if (!$this->isNonEmptyString($item, 1, 140)) {
                return false;
            }
        }

        return true;
    }

    private function isNonEmptyString(mixed $value, int $minLength, int $maxLength): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $length = mb_strlen(trim($value));

        return $length >= $minLength && $length <= $maxLength;
    }

    private function isNullableStringWithinLength(mixed $value, int $maxLength): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return mb_strlen($value) <= $maxLength;
    }

    private function isIntLike(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        return is_string($value) && ctype_digit($value);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $normalizedActions = [];

        foreach ($payload['actions'] as $action) {
            if (!is_array($action)) {
                continue;
            }

            $normalizedAction = [
                'action' => (string) $action['action'],
            ];

            if (isset($action['position']) && $this->isIntLike($action['position'])) {
                $normalizedAction['position'] = (int) $action['position'];
            }

            if (isset($action['slide_id'])) {
                $normalizedAction['slide_id'] = trim((string) $action['slide_id']);
            }

            if (isset($action['slide']) && is_array($action['slide'])) {
                $normalizedAction['slide'] = $this->normalizeSlide($action['slide']);
            }

            if (isset($action['changes']) && is_array($action['changes'])) {
                $normalizedAction['changes'] = $this->normalizeSlide($action['changes'], partial: true);
            }

            $normalizedActions[] = $normalizedAction;
        }

        return [
            'assistant_message' => trim((string) $payload['assistant_message']),
            'actions' => $normalizedActions,
        ];
    }

    /**
     * @param array<string, mixed> $slide
     *
     * @return array<string, mixed>
     */
    private function normalizeSlide(array $slide, bool $partial = false): array
    {
        $normalized = [];

        foreach (['id', 'title', 'type', 'subtitle', 'body', 'notes'] as $field) {
            if (array_key_exists($field, $slide) && is_string($slide[$field])) {
                $normalized[$field] = trim($slide[$field]);
            }
        }

        if (array_key_exists('items', $slide) && is_array($slide['items'])) {
            $normalized['items'] = array_values(array_map(
                static fn (string $item): string => trim($item),
                array_filter($slide['items'], static fn (mixed $item): bool => is_string($item) && trim($item) !== ''),
            ));
        }

        if (array_key_exists('position', $slide) && $this->isIntLike($slide['position'])) {
            $normalized['position'] = (int) $slide['position'];
        }

        if (!$partial && !array_key_exists('items', $normalized) && ($normalized['type'] ?? null) === 'bullet_list') {
            $normalized['items'] = [];
        }

        return $normalized;
    }
}
