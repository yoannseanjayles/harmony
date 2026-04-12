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

    // ── timeline slide validation ─────────────────────────────────────────────

    public function testValidateAcceptsWellFormedTimelineSlide(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);
        $result = $validator->validate(json_encode([
            'assistant_message' => 'Voici la timeline.',
            'actions' => [[
                'action' => 'add_slide',
                'slide' => [
                    'title' => 'Notre parcours',
                    'type' => 'timeline',
                    'items' => [
                        ['year' => '2020', 'label' => 'Fondation', 'description' => 'Début du projet.'],
                        ['year' => '2021', 'label' => 'Beta', 'description' => 'Lancement bêta.'],
                        ['label' => 'Launch'],
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('timeline', $result->actions()[0]['slide']['type']);
        self::assertCount(3, $result->actions()[0]['slide']['items']);
        self::assertSame('Fondation', $result->actions()[0]['slide']['items'][0]['label']);
        self::assertSame('2020', $result->actions()[0]['slide']['items'][0]['year']);
    }

    public function testValidateRejectsTimelineSlideWithMissingItems(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);

        try {
            $validator->validate(json_encode([
                'assistant_message' => 'Timeline sans items',
                'actions' => [[
                    'action' => 'add_slide',
                    'slide' => [
                        'title' => 'Parcours',
                        'type' => 'timeline',
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));

            self::fail('A ResponseValidationException should have been thrown.');
        } catch (ResponseValidationException $exception) {
            self::assertContains(
                'actions[0].slide.items is required for timeline slides.',
                $exception->errors(),
            );
        }
    }

    public function testValidateRejectsTimelineSlideWithTooFewItems(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);

        try {
            $validator->validate(json_encode([
                'assistant_message' => 'Timeline trop courte',
                'actions' => [[
                    'action' => 'add_slide',
                    'slide' => [
                        'title' => 'Parcours',
                        'type' => 'timeline',
                        'items' => [
                            ['label' => 'Seul item'],
                        ],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));

            self::fail('A ResponseValidationException should have been thrown.');
        } catch (ResponseValidationException $exception) {
            self::assertContains(
                'actions[0].slide.items must contain between 2 and 6 timeline entries.',
                $exception->errors(),
            );
        }
    }

    public function testValidateRejectsTimelineSlideWithTooManyItems(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);
        $items = [];
        for ($i = 1; $i <= 7; ++$i) {
            $items[] = ['label' => "Item {$i}"];
        }

        try {
            $validator->validate(json_encode([
                'assistant_message' => 'Timeline trop longue',
                'actions' => [[
                    'action' => 'add_slide',
                    'slide' => [
                        'title' => 'Parcours',
                        'type' => 'timeline',
                        'items' => $items,
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));

            self::fail('A ResponseValidationException should have been thrown.');
        } catch (ResponseValidationException $exception) {
            self::assertContains(
                'actions[0].slide.items must contain between 2 and 6 timeline entries.',
                $exception->errors(),
            );
        }
    }

    public function testValidateRejectsTimelineItemWithMissingLabel(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);

        try {
            $validator->validate(json_encode([
                'assistant_message' => 'Item invalide',
                'actions' => [[
                    'action' => 'add_slide',
                    'slide' => [
                        'title' => 'Parcours',
                        'type' => 'timeline',
                        'items' => [
                            ['year' => '2020', 'description' => 'No label here'],
                            ['label' => 'OK'],
                        ],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));

            self::fail('A ResponseValidationException should have been thrown.');
        } catch (ResponseValidationException $exception) {
            self::assertContains(
                'actions[0].slide.items[0].label must be a non-empty string with max 140 characters.',
                $exception->errors(),
            );
        }
    }

    // ── stats slide validation ────────────────────────────────────────────────

    public function testValidateAcceptsWellFormedStatsSlide(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);
        $result = $validator->validate(json_encode([
            'assistant_message' => 'Voici les chiffres clés.',
            'actions' => [[
                'action' => 'add_slide',
                'slide' => [
                    'title' => 'Impact en chiffres',
                    'type' => 'stats',
                    'stats' => [
                        ['value' => '10M+', 'label' => 'Utilisateurs', 'detail' => 'Monde entier'],
                        ['value' => '98%', 'label' => 'Satisfaction'],
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('stats', $result->actions()[0]['slide']['type']);
        self::assertCount(2, $result->actions()[0]['slide']['stats']);
        self::assertSame('10M+', $result->actions()[0]['slide']['stats'][0]['value']);
        self::assertSame('Monde entier', $result->actions()[0]['slide']['stats'][0]['detail']);
    }

    public function testValidateRejectsStatsSlideWithMissingStats(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);

        try {
            $validator->validate(json_encode([
                'assistant_message' => 'Stats sans données',
                'actions' => [[
                    'action' => 'add_slide',
                    'slide' => [
                        'title' => 'Métriques',
                        'type' => 'stats',
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));

            self::fail('A ResponseValidationException should have been thrown.');
        } catch (ResponseValidationException $exception) {
            self::assertContains(
                'actions[0].slide.stats is required for stats slides.',
                $exception->errors(),
            );
        }
    }

    public function testValidateRejectsStatsSlideWithTooFewStats(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);

        try {
            $validator->validate(json_encode([
                'assistant_message' => 'Une seule stat',
                'actions' => [[
                    'action' => 'add_slide',
                    'slide' => [
                        'title' => 'Métrique',
                        'type' => 'stats',
                        'stats' => [
                            ['value' => '99%', 'label' => 'Uptime'],
                        ],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));

            self::fail('A ResponseValidationException should have been thrown.');
        } catch (ResponseValidationException $exception) {
            self::assertContains(
                'actions[0].slide.stats must contain between 2 and 6 entries.',
                $exception->errors(),
            );
        }
    }

    public function testValidateRejectsStatsSlideWithTooManyStats(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);
        $stats = [];
        for ($i = 1; $i <= 7; ++$i) {
            $stats[] = ['value' => "{$i}%", 'label' => "Stat {$i}"];
        }

        try {
            $validator->validate(json_encode([
                'assistant_message' => 'Trop de stats',
                'actions' => [[
                    'action' => 'add_slide',
                    'slide' => [
                        'title' => 'Métriques',
                        'type' => 'stats',
                        'stats' => $stats,
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));

            self::fail('A ResponseValidationException should have been thrown.');
        } catch (ResponseValidationException $exception) {
            self::assertContains(
                'actions[0].slide.stats must contain between 2 and 6 entries.',
                $exception->errors(),
            );
        }
    }

    public function testValidateRejectsStatEntryWithMissingValue(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);

        try {
            $validator->validate(json_encode([
                'assistant_message' => 'Stat sans valeur',
                'actions' => [[
                    'action' => 'add_slide',
                    'slide' => [
                        'title' => 'Chiffres',
                        'type' => 'stats',
                        'stats' => [
                            ['label' => 'Sans valeur'],
                            ['value' => '50%', 'label' => 'OK'],
                        ],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));

            self::fail('A ResponseValidationException should have been thrown.');
        } catch (ResponseValidationException $exception) {
            self::assertContains(
                'actions[0].slide.stats[0].value must be a non-empty string with max 40 characters.',
                $exception->errors(),
            );
        }
    }

    // ── comparison slide validation ───────────────────────────────────────────

    public function testValidateAcceptsWellFormedComparisonSlide(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);
        $result = $validator->validate(json_encode([
            'assistant_message' => 'Voici la comparaison.',
            'actions' => [[
                'action' => 'add_slide',
                'slide' => [
                    'title' => 'Harmony vs. Concurrence',
                    'type' => 'comparison',
                    'left' => [
                        'heading' => 'Avant',
                        'items' => ['Lent', 'Cher'],
                        'highlight' => 'À éviter',
                    ],
                    'right' => [
                        'heading' => 'Avec Harmony',
                        'items' => ['Rapide', 'Abordable'],
                        'highlight' => 'Recommandé',
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('comparison', $result->actions()[0]['slide']['type']);
        self::assertSame('Avant', $result->actions()[0]['slide']['left']['heading']);
        self::assertSame('À éviter', $result->actions()[0]['slide']['left']['highlight']);
        self::assertCount(2, $result->actions()[0]['slide']['right']['items']);
    }

    public function testValidateRejectsComparisonSlideWithMissingColumns(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);

        try {
            $validator->validate(json_encode([
                'assistant_message' => 'Comparaison incomplète',
                'actions' => [[
                    'action' => 'add_slide',
                    'slide' => [
                        'title' => 'Comparaison',
                        'type' => 'comparison',
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));

            self::fail('A ResponseValidationException should have been thrown.');
        } catch (ResponseValidationException $exception) {
            self::assertContains(
                'actions[0].slide.left is required for comparison slides.',
                $exception->errors(),
            );
            self::assertContains(
                'actions[0].slide.right is required for comparison slides.',
                $exception->errors(),
            );
        }
    }

    public function testValidateRejectsComparisonColumnWithMissingHeading(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);

        try {
            $validator->validate(json_encode([
                'assistant_message' => 'Colonne sans heading',
                'actions' => [[
                    'action' => 'add_slide',
                    'slide' => [
                        'title' => 'Comparaison',
                        'type' => 'comparison',
                        'left' => ['items' => ['Item A']],
                        'right' => ['heading' => 'Après', 'items' => ['Item B']],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));

            self::fail('A ResponseValidationException should have been thrown.');
        } catch (ResponseValidationException $exception) {
            self::assertContains(
                'actions[0].slide.left.heading must be a non-empty string with max 80 characters.',
                $exception->errors(),
            );
        }
    }

    public function testValidateRejectsComparisonColumnWithTooManyItems(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $validator = new ResponseValidator(new ResponseSchema(), $logger);
        $items = [];
        for ($i = 1; $i <= 7; ++$i) {
            $items[] = "Item {$i}";
        }

        try {
            $validator->validate(json_encode([
                'assistant_message' => "Trop d'items dans une colonne",
                'actions' => [[
                    'action' => 'add_slide',
                    'slide' => [
                        'title' => 'Comparaison',
                        'type' => 'comparison',
                        'left' => ['heading' => 'Gauche', 'items' => $items],
                        'right' => ['heading' => 'Droite', 'items' => ['Item']],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));

            self::fail('A ResponseValidationException should have been thrown.');
        } catch (ResponseValidationException $exception) {
            self::assertContains(
                'actions[0].slide.left.items must contain between 1 and 6 strings.',
                $exception->errors(),
            );
        }
    }

    public function testValidateNormalizesPhase3FieldsOnAddSlide(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $validator = new ResponseValidator(new ResponseSchema(), $logger);

        $result = $validator->validate(json_encode([
            'assistant_message' => 'Normalisation Phase 3.',
            'actions' => [[
                'action' => 'add_slide',
                'slide' => [
                    'title' => 'Stats',
                    'type' => 'stats',
                    'stats' => [
                        ['value' => ' 10M+ ', 'label' => ' Users ', 'detail' => ' Global '],
                        ['value' => '98%', 'label' => 'Satisfaction'],
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        $stats = $result->actions()[0]['slide']['stats'];
        self::assertSame('10M+', $stats[0]['value']);
        self::assertSame('Users', $stats[0]['label']);
        self::assertSame('Global', $stats[0]['detail']);
    }
}
