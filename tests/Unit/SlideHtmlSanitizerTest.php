<?php

namespace App\Tests\Unit;

use App\Slide\SlideHtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class SlideHtmlSanitizerTest extends TestCase
{
    private SlideHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new SlideHtmlSanitizer();
    }

    // ── sanitizeText — strips ALL HTML ───────────────────────────────────────

    public function testSanitizeTextReturnsPlainString(): void
    {
        self::assertSame('Hello world', $this->sanitizer->sanitizeText('Hello world'));
    }

    public function testSanitizeTextStripsScriptTag(): void
    {
        self::assertSame('alert("xss")', $this->sanitizer->sanitizeText('<script>alert("xss")</script>'));
    }

    public function testSanitizeTextStripsInlineEventHandlers(): void
    {
        $result = $this->sanitizer->sanitizeText('<img src="x" onerror="alert(1)">text');

        self::assertStringNotContainsString('<img', $result);
        self::assertStringNotContainsString('onerror', $result);
        self::assertSame('text', $result);
    }

    public function testSanitizeTextStripsHtmlComments(): void
    {
        $result = $this->sanitizer->sanitizeText('<!-- comment -->text');

        self::assertStringNotContainsString('<!--', $result);
        self::assertStringContainsString('text', $result);
    }

    public function testSanitizeTextStripsBoldAndEmphasis(): void
    {
        self::assertSame('bold text', $this->sanitizer->sanitizeText('<b>bold</b> text'));
        self::assertSame('italic', $this->sanitizer->sanitizeText('<em>italic</em>'));
    }

    public function testSanitizeTextStripsLinks(): void
    {
        $result = $this->sanitizer->sanitizeText('<a href="javascript:evil()">click</a>');

        self::assertSame('click', $result);
        self::assertStringNotContainsString('javascript:', $result);
    }

    public function testSanitizeTextPreservesTextContent(): void
    {
        self::assertSame('Harmony — AI-Powered Presentations', $this->sanitizer->sanitizeText('Harmony — AI-Powered Presentations'));
    }

    public function testSanitizeTextPreservesSpecialCharacters(): void
    {
        self::assertSame('10% & more', $this->sanitizer->sanitizeText('10% & more'));
    }

    public function testSanitizeTextHandlesEmptyString(): void
    {
        self::assertSame('', $this->sanitizer->sanitizeText(''));
    }

    public function testSanitizeTextStripsNestedTags(): void
    {
        self::assertSame('hello', $this->sanitizer->sanitizeText('<div><p><span>hello</span></p></div>'));
    }

    public function testSanitizeTextStripsSvgWithOnload(): void
    {
        $result = $this->sanitizer->sanitizeText('<svg onload="alert(1)"><circle/></svg>text');

        self::assertStringNotContainsString('<svg', $result);
        self::assertStringNotContainsString('onload', $result);
        self::assertStringContainsString('text', $result);
    }

    public function testSanitizeTextStripsIframeTag(): void
    {
        $result = $this->sanitizer->sanitizeText('<iframe src="https://evil.com"></iframe>safe');

        self::assertStringNotContainsString('<iframe', $result);
        self::assertStringContainsString('safe', $result);
    }

    public function testSanitizeTextStripsObjectAndEmbedTags(): void
    {
        $result = $this->sanitizer->sanitizeText('<object data="evil.swf"></object><embed src="evil.swf">text');

        self::assertStringNotContainsString('<object', $result);
        self::assertStringNotContainsString('<embed', $result);
        self::assertStringContainsString('text', $result);
    }

    // ── sanitizeRichText — whitelist only ────────────────────────────────────

    public function testSanitizeRichTextPreservesWhitelistedTags(): void
    {
        $input = '<strong>bold</strong> and <em>italic</em> text';
        $result = $this->sanitizer->sanitizeRichText($input);

        self::assertStringContainsString('<strong>', $result);
        self::assertStringContainsString('<em>', $result);
        self::assertStringContainsString('bold', $result);
        self::assertStringContainsString('italic', $result);
    }

    public function testSanitizeRichTextPreservesBrTag(): void
    {
        $result = $this->sanitizer->sanitizeRichText('line one<br>line two');

        self::assertStringContainsString('<br', $result);
        self::assertStringContainsString('line one', $result);
        self::assertStringContainsString('line two', $result);
    }

    public function testSanitizeRichTextStripsScriptEvenIfWhitelisted(): void
    {
        $result = $this->sanitizer->sanitizeRichText('<script>alert(1)</script>text');

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('text', $result);
    }

    public function testSanitizeRichTextStripsNonWhitelistedTags(): void
    {
        $result = $this->sanitizer->sanitizeRichText('<div><p>paragraph</p></div>');

        self::assertStringNotContainsString('<div>', $result);
        self::assertStringNotContainsString('<p>', $result);
        self::assertStringContainsString('paragraph', $result);
    }

    public function testSanitizeRichTextStripsAllAttributes(): void
    {
        $result = $this->sanitizer->sanitizeRichText('<strong class="highlight" onclick="evil()">text</strong>');

        self::assertStringNotContainsString('onclick', $result);
        self::assertStringNotContainsString('class=', $result);
        self::assertStringContainsString('<strong>', $result);
        self::assertStringContainsString('text', $result);
    }

    public function testSanitizeRichTextStripsHrefAttributeFromAllowedTags(): void
    {
        // <span> is allowed but href/src are NOT
        $result = $this->sanitizer->sanitizeRichText('<span href="javascript:evil()">text</span>');

        self::assertStringNotContainsString('javascript:', $result);
        self::assertStringContainsString('<span>', $result);
        self::assertStringContainsString('text', $result);
    }

    public function testSanitizeRichTextHandlesXssPayloadWithEncodedChars(): void
    {
        $result = $this->sanitizer->sanitizeRichText('<script>document.write("<img src=x onerror=alert(1)>")</script>safe');

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('safe', $result);
    }

    public function testSanitizeRichTextHandlesEmptyString(): void
    {
        self::assertSame('', $this->sanitizer->sanitizeRichText(''));
    }

    public function testSanitizeRichTextPreservesAllWhitelistedTags(): void
    {
        foreach (SlideHtmlSanitizer::ALLOWED_RICH_TEXT_TAGS as $tag) {
            $input = "<{$tag}>content</{$tag}>";
            $result = $this->sanitizer->sanitizeRichText($input);

            self::assertStringContainsString('content', $result, "Content should survive for tag <{$tag}>");
        }
    }
}
