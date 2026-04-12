<?php

namespace App\Slide;

/**
 * Sanitizes text content originating from LLM responses before it is passed into Twig templates.
 *
 * Two modes are provided:
 *  - sanitizeText()     : strips ALL HTML tags — use for every plain-text field (titles, labels, …)
 *  - sanitizeRichText() : strips non-whitelisted tags and all attributes — use ONLY when the
 *                         sanitized value is rendered with |raw in a Twig template.
 *
 * The whitelist is intentionally narrow: only basic inline and line-break elements.
 * No attributes are ever allowed (prevents attribute-based injection such as event handlers or href="javascript:").
 */
final class SlideHtmlSanitizer
{
    /**
     * HTML elements allowed in rich-text zones.
     * Inline formatting only — no block containers, no script/style, no links.
     *
     * @var list<string>
     */
    public const ALLOWED_RICH_TEXT_TAGS = [
        'strong',
        'em',
        'b',
        'i',
        'br',
        'span',
        'u',
        's',
    ];

    /**
     * Strip ALL HTML from a plain-text field.
     *
     * This is the default mode for every slide text field.
     * Twig auto-escapes the result, so no |raw is needed or allowed.
     */
    public function sanitizeText(string $input): string
    {
        return strip_tags($input);
    }

    /**
     * Strip all non-whitelisted HTML tags and all attributes.
     *
     * Use this ONLY for fields that will be rendered with |raw inside a Twig template.
     * The output is still HTML-safe: all attributes are stripped, entity-encoded characters
     * from the original string are preserved, and only the whitelisted void/inline elements remain.
     */
    public function sanitizeRichText(string $input): string
    {
        // Step 1: strip all attributes from every tag to neutralise event handlers and href="javascript:…"
        $noAttributes = $this->stripAllAttributes($input);

        // Step 2: apply the whitelist — non-listed elements are removed (content preserved)
        return strip_tags($noAttributes, self::ALLOWED_RICH_TEXT_TAGS);
    }

    /**
     * Remove every HTML attribute from every tag while keeping the tag itself.
     * This runs before the whitelist strip so that even tags that will later be removed
     * cannot carry dangerous attributes if they survive through edge-cases.
     */
    private function stripAllAttributes(string $html): string
    {
        // Match opening tags (with optional attributes) and replace with just the tag name.
        // Handles self-closing tags as well (e.g. <br />).
        $result = preg_replace('/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*(\/?)\s*>/', '<$1$2>', $html);

        return $result ?? $html;
    }
}
