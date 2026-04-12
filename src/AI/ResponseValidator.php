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

        $containsConfirmationRequest = false;

        foreach ($actions as $index => $action) {
            $path = sprintf('actions[%d]', $index);
            if (!is_array($action)) {
                $errors[] = $path.' must be an object.';
                continue;
            }

            $actionName = $this->validateAction($action, $path, $errors);
            if ($actionName === 'request_confirmation') {
                $containsConfirmationRequest = true;
            }
        }

        if ($containsConfirmationRequest && count($actions) !== 1) {
            $errors[] = 'request_confirmation must be the only top-level action.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $action
     * @param list<string> $errors
     */
    private function validateAction(array $action, string $path, array &$errors, bool $allowConfirmation = true): ?string
    {
        $supportedActions = $allowConfirmation
            ? $this->responseSchema->supportedActions()
            : array_values(array_filter(
                $this->responseSchema->supportedActions(),
                static fn (string $supportedAction): bool => $supportedAction !== 'request_confirmation',
            ));

        $actionName = $action['action'] ?? null;
        if (!is_string($actionName) || !in_array($actionName, $supportedActions, true)) {
            $errors[] = $path.'.action is invalid.';

            return null;
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
            case 'reorder_slides':
                $this->validateReorderSlidesAction($action, $path, $errors);
                break;
            case 'request_confirmation':
                $this->validateRequestConfirmationAction($action, $path, $errors);
                break;
        }

        return $actionName;
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
     * @param array<string, mixed> $action
     * @param list<string> $errors
     */
    private function validateReorderSlidesAction(array $action, string $path, array &$errors): void
    {
        $slideIds = $action['slide_ids'] ?? null;
        if (!is_array($slideIds) || $slideIds === [] || count($slideIds) > 50) {
            $errors[] = $path.'.slide_ids must contain between 1 and 50 slide identifiers.';

            return;
        }

        $normalizedIds = [];
        foreach ($slideIds as $index => $slideId) {
            if (!$this->isNonEmptyString($slideId, 1, 80)) {
                $errors[] = sprintf('%s.slide_ids[%d] must be a non-empty string with max 80 characters.', $path, $index);
                continue;
            }

            $normalizedIds[] = trim((string) $slideId);
        }

        if (count($normalizedIds) !== count(array_unique($normalizedIds))) {
            $errors[] = $path.'.slide_ids must not contain duplicates.';
        }
    }

    /**
     * @param array<string, mixed> $action
     * @param list<string> $errors
     */
    private function validateRequestConfirmationAction(array $action, string $path, array &$errors): void
    {
        if (!$this->isNonEmptyString($action['summary'] ?? null, 3, 240)) {
            $errors[] = $path.'.summary must contain between 3 and 240 characters.';
        }

        $proposedActions = $action['proposed_actions'] ?? null;
        if (!is_array($proposedActions) || $proposedActions === [] || count($proposedActions) > 12) {
            $errors[] = $path.'.proposed_actions must contain between 1 and 12 actions.';

            return;
        }

        foreach ($proposedActions as $index => $proposedAction) {
            $nestedPath = sprintf('%s.proposed_actions[%d]', $path, $index);
            if (!is_array($proposedAction)) {
                $errors[] = $nestedPath.' must be an object.';
                continue;
            }

            $this->validateAction($proposedAction, $nestedPath, $errors, false);
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

        $resolvedType = null;
        if (array_key_exists('type', $slide)) {
            $type = $slide['type'];
            if (!is_string($type) || !in_array($type, $this->responseSchema->supportedSlideTypes(), true)) {
                $errors[] = $path.'.type is not supported.';
            } else {
                $resolvedType = $type;
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

        if ($requireFullDefinition && !array_key_exists('items', $slide) && $resolvedType === 'bullet_list') {
            $errors[] = $path.'.items must be provided for bullet_list slides.';
        }

        if (array_key_exists('items', $slide) && $resolvedType !== 'timeline' && !$this->validateItems($slide['items'])) {
            $errors[] = $path.'.items must contain between 1 and 6 strings, each between 1 and 140 characters.';
        }

        // Phase 3 type-specific validation
        if ($resolvedType === 'timeline') {
            $this->validateTimelineSlide($slide, $path, $errors, $requireFullDefinition);
        } elseif ($resolvedType === 'stats') {
            $this->validateStatsSlide($slide, $path, $errors, $requireFullDefinition);
        } elseif ($resolvedType === 'comparison') {
            $this->validateComparisonSlide($slide, $path, $errors, $requireFullDefinition);
        }
    }

    /**
     * @param array<string, mixed> $slide
     * @param list<string> $errors
     */
    private function validateTimelineSlide(array $slide, string $path, array &$errors, bool $requireFullDefinition): void
    {
        if (!array_key_exists('items', $slide)) {
            if ($requireFullDefinition) {
                $errors[] = $path.'.items is required for timeline slides.';
            }

            return;
        }

        $items = $slide['items'];
        if (!is_array($items) || count($items) < 2 || count($items) > 6) {
            $errors[] = $path.'.items must contain between 2 and 6 timeline entries.';

            return;
        }

        foreach ($items as $i => $item) {
            $itemPath = sprintf('%s.items[%d]', $path, $i);
            if (!is_array($item)) {
                $errors[] = $itemPath.' must be an object.';
                continue;
            }

            if (!$this->isNonEmptyString($item['label'] ?? null, 1, 140)) {
                $errors[] = $itemPath.'.label must be a non-empty string with max 140 characters.';
            }

            if (array_key_exists('year', $item) && !$this->isNullableStringWithinLength($item['year'], 20)) {
                $errors[] = $itemPath.'.year must be a string with max 20 characters.';
            }

            if (array_key_exists('description', $item) && !$this->isNullableStringWithinLength($item['description'], 300)) {
                $errors[] = $itemPath.'.description must be a string with max 300 characters.';
            }
        }
    }

    /**
     * @param array<string, mixed> $slide
     * @param list<string> $errors
     */
    private function validateStatsSlide(array $slide, string $path, array &$errors, bool $requireFullDefinition): void
    {
        if (!array_key_exists('stats', $slide)) {
            if ($requireFullDefinition) {
                $errors[] = $path.'.stats is required for stats slides.';
            }

            return;
        }

        $stats = $slide['stats'];
        if (!is_array($stats) || count($stats) < 2 || count($stats) > 6) {
            $errors[] = $path.'.stats must contain between 2 and 6 entries.';

            return;
        }

        foreach ($stats as $i => $stat) {
            $statPath = sprintf('%s.stats[%d]', $path, $i);
            if (!is_array($stat)) {
                $errors[] = $statPath.' must be an object.';
                continue;
            }

            if (!$this->isNonEmptyString($stat['value'] ?? null, 1, 40)) {
                $errors[] = $statPath.'.value must be a non-empty string with max 40 characters.';
            }

            if (!$this->isNonEmptyString($stat['label'] ?? null, 1, 80)) {
                $errors[] = $statPath.'.label must be a non-empty string with max 80 characters.';
            }

            if (array_key_exists('detail', $stat) && !$this->isNullableStringWithinLength($stat['detail'], 140)) {
                $errors[] = $statPath.'.detail must be a string with max 140 characters.';
            }
        }
    }

    /**
     * @param array<string, mixed> $slide
     * @param list<string> $errors
     */
    private function validateComparisonSlide(array $slide, string $path, array &$errors, bool $requireFullDefinition): void
    {
        foreach (['left', 'right'] as $side) {
            $colPath = $path.'.'.$side;

            if (!array_key_exists($side, $slide)) {
                if ($requireFullDefinition) {
                    $errors[] = $colPath.' is required for comparison slides.';
                }
                continue;
            }

            $column = $slide[$side];
            if (!is_array($column)) {
                $errors[] = $colPath.' must be an object.';
                continue;
            }

            if (!$this->isNonEmptyString($column['heading'] ?? null, 1, 80)) {
                $errors[] = $colPath.'.heading must be a non-empty string with max 80 characters.';
            }

            $items = $column['items'] ?? null;
            if (!is_array($items) || count($items) < 1 || count($items) > 6) {
                $errors[] = $colPath.'.items must contain between 1 and 6 strings.';
            } elseif (!$this->validateItems($items)) {
                $errors[] = $colPath.'.items must contain between 1 and 6 strings, each between 1 and 140 characters.';
            }

            if (array_key_exists('highlight', $column) && !$this->isNullableStringWithinLength($column['highlight'], 80)) {
                $errors[] = $colPath.'.highlight must be a string with max 80 characters.';
            }
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
        return [
            'assistant_message' => trim((string) $payload['assistant_message']),
            'actions' => array_values(array_map(
                fn (array $action): array => $this->normalizeAction($action),
                array_filter($payload['actions'], 'is_array'),
            )),
        ];
    }

    /**
     * @param array<string, mixed> $action
     *
     * @return array<string, mixed>
     */
    private function normalizeAction(array $action): array
    {
        $normalizedAction = [
            'action' => trim((string) ($action['action'] ?? '')),
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

        if (isset($action['slide_ids']) && is_array($action['slide_ids'])) {
            $normalizedAction['slide_ids'] = $this->normalizeSlideIdentifiers($action['slide_ids']);
        }

        if (isset($action['summary']) && is_string($action['summary'])) {
            $normalizedAction['summary'] = trim($action['summary']);
        }

        if (isset($action['proposed_actions']) && is_array($action['proposed_actions'])) {
            $normalizedAction['proposed_actions'] = array_values(array_map(
                fn (array $proposedAction): array => $this->normalizeAction($proposedAction),
                array_filter($action['proposed_actions'], 'is_array'),
            ));
        }

        return $normalizedAction;
    }

    /**
     * @param list<mixed> $slideIds
     *
     * @return list<string>
     */
    private function normalizeSlideIdentifiers(array $slideIds): array
    {
        return array_values(array_map(
            static fn (string $slideId): string => trim($slideId),
            array_filter($slideIds, static fn (mixed $slideId): bool => is_string($slideId) && trim($slideId) !== ''),
        ));
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

        if (array_key_exists('items', $slide) && is_array($slide['items']) && ($normalized['type'] ?? null) !== 'timeline') {
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

        // Phase 3 fields
        if (array_key_exists('items', $slide) && is_array($slide['items']) && ($normalized['type'] ?? null) === 'timeline') {
            $normalized['items'] = $this->normalizeTimelineItems($slide['items']);
        }
        if (array_key_exists('stats', $slide) && is_array($slide['stats'])) {
            $normalized['stats'] = $this->normalizeStatsEntries($slide['stats']);
        }

        foreach (['left', 'right'] as $side) {
            if (array_key_exists($side, $slide) && is_array($slide[$side])) {
                $normalized[$side] = $this->normalizeComparisonColumn($slide[$side]);
            }
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<array<string, string>>
     */
    private function normalizeTimelineItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $entry = ['label' => trim((string) ($item['label'] ?? ''))];
            if (isset($item['year']) && is_string($item['year'])) {
                $entry['year'] = trim($item['year']);
            }
            if (isset($item['description']) && is_string($item['description'])) {
                $entry['description'] = trim($item['description']);
            }
            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $stats
     *
     * @return list<array<string, string>>
     */
    private function normalizeStatsEntries(array $stats): array
    {
        $normalized = [];
        foreach ($stats as $stat) {
            if (!is_array($stat)) {
                continue;
            }
            $entry = [
                'value' => trim((string) ($stat['value'] ?? '')),
                'label' => trim((string) ($stat['label'] ?? '')),
            ];
            if (isset($stat['detail']) && is_string($stat['detail'])) {
                $entry['detail'] = trim($stat['detail']);
            }
            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $column
     *
     * @return array<string, mixed>
     */
    private function normalizeComparisonColumn(array $column): array
    {
        $normalized = [
            'heading' => trim((string) ($column['heading'] ?? '')),
        ];

        if (isset($column['items']) && is_array($column['items'])) {
            $normalized['items'] = array_values(array_map(
                static fn (string $item): string => trim($item),
                array_filter($column['items'], static fn (mixed $item): bool => is_string($item) && trim($item) !== ''),
            ));
        }

        if (isset($column['highlight']) && is_string($column['highlight'])) {
            $normalized['highlight'] = trim($column['highlight']);
        }

        return $normalized;
    }
}
