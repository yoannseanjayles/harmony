<?php

namespace App\AI;

final class ResponseSchema
{
    /**
     * @return list<string>
     */
    public function supportedActions(): array
    {
        return [
            'add_slide',
            'update_slide',
            'remove_slide',
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedSlideTypes(): array
    {
        return [
            'title',
            'bullet_list',
            'quote',
            'summary',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'object',
            'required' => ['assistant_message', 'actions'],
            'properties' => [
                'assistant_message' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 2000,
                ],
                'actions' => [
                    'type' => 'array',
                    'minItems' => 0,
                    'maxItems' => 12,
                    'items' => [
                        'oneOf' => [
                            [
                                'action' => 'add_slide',
                                'required' => ['action', 'slide'],
                            ],
                            [
                                'action' => 'update_slide',
                                'required' => ['action', 'slide_id', 'changes'],
                            ],
                            [
                                'action' => 'remove_slide',
                                'required' => ['action', 'slide_id'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function promptInstructions(): string
    {
        return implode("\n", [
            'Return ONLY valid JSON. No markdown fences, no commentary before or after the JSON.',
            'The JSON object must use this shape:',
            '{',
            '  "assistant_message": "short French message for the user",',
            '  "actions": [',
            '    {"action":"add_slide","position":1,"slide":{"id":"optional-id","title":"Slide title","type":"bullet_list","subtitle":"optional","body":"optional","items":["bullet 1","bullet 2"],"notes":"optional"}}',
            '  ]',
            '}',
            'Supported actions: add_slide, update_slide, remove_slide.',
            'Supported slide types: '.implode(', ', $this->supportedSlideTypes()).'.',
            'Constraints:',
            '- assistant_message: 1..2000 characters',
            '- title: 3..120 characters',
            '- subtitle: up to 140 characters',
            '- body: up to 600 characters',
            '- notes: up to 400 characters',
            '- items: 1..6 strings, each 1..140 characters',
            '- position: integer between 1 and 50',
            'Use update_slide with {"slide_id":"existing-id","changes":{...}}.',
            'Use remove_slide with {"slide_id":"existing-id"}.',
        ]);
    }
}
