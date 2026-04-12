<?php

namespace App\Tests\Unit;

use App\AI\ResponseSchema;
use App\AI\ResponseValidationException;
use App\AI\ResponseValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ResponseValidatorTest extends TestCase
{
    public function testValidateAcceptsWellFormedAddSlidePayload(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);
        $result = $validator->validate(json_encode([
            'assistant_message' => 'Voici une proposition de deck.',
            'actions' => [[
                'action' => 'add_slide',
                'position' => 1,
                'slide' => [
                    'id' => 'slide-vision',
                    'title' => 'Vision',
                    'type' => 'bullet_list',
                    'items' => ['Point 1', 'Point 2'],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('Voici une proposition de deck.', $result->assistantMessage());
        self::assertCount(1, $result->actions());
        self::assertFalse($result->wasLocallyRepaired());
        self::assertSame('add_slide', $result->actions()[0]['action']);
        self::assertSame('bullet_list', $result->actions()[0]['slide']['type']);
    }

    public function testValidateRepairsJsonFencesAndTrailingCommaLocally(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);
        $result = $validator->validate(<<<'JSON'
```json
{
  "assistant_message": "Reponse reparee",
  "actions": [
    {
      "action": "add_slide",
      "slide": {
        "title": "Slide reparee",
        "type": "summary",
        "body": "Contenu"
      }
    }
  ],
}
```
JSON);

        self::assertTrue($result->wasLocallyRepaired());
        self::assertSame('Reponse reparee', $result->assistantMessage());
        self::assertSame('summary', $result->actions()[0]['slide']['type']);
    }

    public function testValidateRejectsUnknownSlideTypesAndLogsPayload(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'ai_response_validation_failed',
                self::callback(static function (array $context): bool {
                    return str_contains((string) ($context['payload'] ?? ''), '"unknown_type"')
                        && in_array('actions[0].slide.type is not supported.', $context['errors'] ?? [], true);
                }),
            );

        $validator = new ResponseValidator(new ResponseSchema(), $logger);

        $this->expectException(ResponseValidationException::class);
        $this->expectExceptionMessage('AI response validation failed');

        $validator->validate(json_encode([
            'assistant_message' => 'Payload invalide',
            'actions' => [[
                'action' => 'add_slide',
                'slide' => [
                    'title' => 'Roadmap',
                    'type' => 'unknown_type',
                    'body' => 'Non supporte',
                ],
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    public function testValidateRejectsOutOfBoundsItemsCount(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);

        try {
            $validator->validate(json_encode([
                'assistant_message' => 'Trop de points',
                'actions' => [[
                    'action' => 'add_slide',
                    'slide' => [
                        'title' => 'Liste surchargee',
                        'type' => 'bullet_list',
                        'items' => ['1', '2', '3', '4', '5', '6', '7'],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));

            self::fail('A ResponseValidationException should have been thrown.');
        } catch (ResponseValidationException $exception) {
            self::assertContains(
                'actions[0].slide.items must contain between 1 and 6 strings, each between 1 and 140 characters.',
                $exception->errors(),
            );
        }
    }

    public function testValidateAcceptsRequestConfirmationWithNestedReorderAction(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);
        $result = $validator->validate(json_encode([
            'assistant_message' => 'Je peux reordonner les slides. Confirmez-vous ?',
            'actions' => [[
                'action' => 'request_confirmation',
                'summary' => 'Reordonner les slides pour mettre la synthese en premier.',
                'proposed_actions' => [[
                    'action' => 'reorder_slides',
                    'slide_ids' => ['slide-2', 'slide-1', 'slide-3'],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('request_confirmation', $result->actions()[0]['action']);
        self::assertSame('reorder_slides', $result->actions()[0]['proposed_actions'][0]['action']);
        self::assertSame(['slide-2', 'slide-1', 'slide-3'], $result->actions()[0]['proposed_actions'][0]['slide_ids']);
    }

    public function testValidateRejectsRequestConfirmationMixedWithOtherTopLevelActions(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);

        try {
            $validator->validate(json_encode([
                'assistant_message' => 'Payload invalide',
                'actions' => [
                    [
                        'action' => 'request_confirmation',
                        'summary' => 'Merci de confirmer.',
                        'proposed_actions' => [[
                            'action' => 'reorder_slides',
                            'slide_ids' => ['slide-2', 'slide-1'],
                        ]],
                    ],
                    [
                        'action' => 'remove_slide',
                        'slide_id' => 'slide-3',
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            self::fail('A ResponseValidationException should have been thrown.');
        } catch (ResponseValidationException $exception) {
            self::assertContains(
                'request_confirmation must be the only top-level action.',
                $exception->errors(),
            );
        }
    }
}
