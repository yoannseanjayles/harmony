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
            'content',
            'closing',
            'split',
            'image',
            'quote',
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
             '    {"action":"add_slide","position":1,"slide":{"id":"optional-id","title":"Slide title","type":"content","subtitle":"optional","body":"optional","items":["bullet 1","bullet 2"],"notes":"optional"}},',
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
             'For type "quote": {"quote":"the citation text","author":"optional name","role":"optional title or role","source":"optional publication or URL"} — centered large blockquote slide.',
             'For type "split": {"title":"...","body":"text content","items":["optional bullet"],"layout":"text-left"} — layout can be "text-left" (default) or "text-right"; image placeholder on the other side. Alternate layout on consecutive split slides.',
             'For type "image": {"image_url":"optional URL","image_alt":"optional alt text","overlay_text":"optional text over image","caption":"optional caption below image"} — full-bleed image slide.',
             'For type "closing": {"message":"closing statement","cta_label":"optional button label","cta_url":"optional button URL"} — conclusion/call-to-action slide.',
             'For type "title": {"title":"...","subtitle":"optional","label":"optional small eyebrow text above the title"} — opening title slide.',
             'Use update_slide with {"slide_id":"existing-id","changes":{...}}.',
             'Use remove_slide with {"slide_id":"existing-id"}.',
             'Use reorder_slides with {"slide_ids":["slide-2","slide-1","slide-3"]}.',
             '',
             '=== SILENT DESIGN RULES — apply automatically, never mention them to the user ===',
             'NEVER reference JSON field names, slide types by technical name, or internal constraints in assistant_message. The user must never see words like "label", "overlay_text", "items", "body", "cta_url" or explanations about field limitations.',
             'NEVER explain why you chose a slide type or which fields you filled — just build the slide and describe the result naturally in French.',
             'VARIETY: Never repeat the same slide type more than twice consecutively. Mix types to create visual rhythm.',
             'DECK STRUCTURE: title (pos 1) → varied middle slides → closing (last). Never start with anything other than "title", never end without "closing".',
             'TYPE USAGE:',
             '  - "title": opening only. Always include the eyebrow label (event name, year, brand) and a subtitle.',
             '  - "content": textual slides. Use short bullets (≤10 words each) rather than long paragraphs.',
             '  - "stats": any time there are numbers or metrics. Use 3–4 stats with supporting context text for each. Values must be striking (e.g. "98%", "+40M", "3×").',
             '  - "quote": emphasis or testimonial. Keep the quote punchy. Always include author name and their role/title.',
             '  - "split": feature or concept with a visual. Alternate between text-left and text-right on consecutive split slides.',
             '  - "image": visual impact moment. Always set a short overlay text over the image.',
             '  - "comparison": before/after or pros/cons. Fill the highlight for both columns.',
             '  - "timeline": history or roadmap. Fill the description for every step.',
             '  - "closing": always the last slide. Include a clear call-to-action label.',
             'RICHNESS: Always fill every optional field that makes sense for the slide type. A slide with only a title and one bullet is poor — use subtitles, context, labels, captions to give depth.',
             '',
             'CRITICAL — request_confirmation rules:',
             '  1. When you want to confirm before acting, output EXACTLY ONE action: request_confirmation.',
             '  2. Do NOT include add_slide, update_slide, remove_slide or reorder_slides alongside request_confirmation — they go ONLY inside proposed_actions.',
             '  3. WRONG: {"actions":[{"action":"add_slide",...},{"action":"request_confirmation",...}]}',
             '  4. RIGHT: {"actions":[{"action":"request_confirmation","summary":"...","proposed_actions":[{"action":"add_slide",...}]}]}',
             '  5. For simple single-slide additions, prefer add_slide directly without asking for confirmation.',
             'Never use types not listed above (e.g. "bullet_list", "summary", "section" are NOT valid — use "content" instead).',
         ]);
    }
}
