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
            'reorder_slides',
            'request_confirmation',
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
            'timeline',
            'stats',
            'comparison',
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
                            [
                                'action' => 'reorder_slides',
                                'required' => ['action', 'slide_ids'],
                            ],
                            [
                                'action' => 'request_confirmation',
                                'required' => ['action', 'summary', 'proposed_actions'],
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
             '    {"action":"request_confirmation","summary":"short confirmation summary","proposed_actions":[{"action":"reorder_slides","slide_ids":["slide-2","slide-1"]}]}',
             '  ]',
             '}',
             'Supported actions: add_slide, update_slide, remove_slide, reorder_slides, request_confirmation.',
             'Supported slide types: '.implode(', ', $this->supportedSlideTypes()).'.',
             'Constraints:',
             '- assistant_message: 1..2000 characters',
            '- title: 3..120 characters',
            '- subtitle: up to 140 characters',
            '- body: up to 600 characters',
            '- notes: up to 400 characters',
             '- items: 1..6 strings, each 1..140 characters',
             '- position: integer between 1 and 50',
             'For type "timeline": {"title":"...","items":[{"year":"2020","label":"Founded","description":"optional"},...]}, 2..6 items.',
             'For type "stats": {"title":"...","stats":[{"value":"98%","label":"Satisfaction","detail":"optional"},...]}, 2..6 stats.',
             'For type "comparison": {"title":"...","left":{"heading":"Before","items":["..."],"highlight":"optional"},"right":{"heading":"After","items":["..."],"highlight":"optional"}}, 1..6 items per column.',
             'Use update_slide with {"slide_id":"existing-id","changes":{...}}.',
             'Use remove_slide with {"slide_id":"existing-id"}.',
             'Use reorder_slides with {"slide_ids":["slide-2","slide-1","slide-3"]}.',
             'Use request_confirmation only for structural changes that add, remove or reorder slides, or otherwise need explicit user approval.',
             'Use update_slide directly for small textual edits on one existing slide without asking for confirmation.',
             'When using request_confirmation, it must be the only top-level action and the real mutations must go inside proposed_actions.',
         ]);
    }
}
