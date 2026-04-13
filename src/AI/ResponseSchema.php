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
             '=== DESIGN PRINCIPLES — follow these to create visually rich, varied decks ===',
             'VARIETY: Never use the same slide type more than twice in a row. A great deck mixes types to create visual rhythm.',
             'DECK STRUCTURE: For a complete deck, follow this pattern — title → (content|split|stats|quote|image|comparison|timeline) × N → closing.',
             'TYPE SELECTION GUIDE:',
             '  - "title": Only for the opening slide (position 1). Always set label (e.g. "Présentation", "2024") and subtitle.',
             '  - "content": Use for textual explanations. Prefer items (bullets) over long body. Keep body under 2 sentences.',
             '  - "stats": Use whenever there are numbers, metrics, KPIs, or data points. Always fill "detail" for context. 3–4 stats is ideal.',
             '  - "quote": Use for testimonials, key messages, or emphasis slides. Always set author and role. Keep the quote short and punchy.',
             '  - "split": Use for feature descriptions, product/service details, or text+visual pairing. Alternate layout (text-left / text-right) between consecutive split slides.',
             '  - "image": Use for visual impact slides — before/after, product showcase, atmosphere. Set overlay_text for a caption over the image.',
             '  - "comparison": Use for before/after, pros/cons, competitors. Always fill highlight for each column.',
             '  - "timeline": Use for history, roadmap, or step-by-step processes. Always fill description for each item.',
             '  - "closing": Always end the deck with a closing slide. Set a clear cta_label and cta_url if relevant.',
             'OPTIONAL FIELDS: Always fill optional fields when possible — they make slides visually richer:',
             '  - "label" on title slides gives the eyebrow context (e.g. company name, year, event).',
             '  - "subtitle" on title/split slides adds hierarchy.',
             '  - "detail" on stats gives meaning to numbers.',
             '  - "overlay_text" on image slides creates text-over-image impact.',
             '  - "caption" on image/split slides adds attribution or context.',
             'CONTENT QUALITY: Write concise, impactful copy. Titles should be bold statements, not descriptions. Bullets should be short (5–10 words). Stats values should be striking ("98%", "+40M", "3×").',
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
