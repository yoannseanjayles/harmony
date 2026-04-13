<?php

namespace App\Tests\Functional;

use App\AI\ResponseValidationException;
use App\AI\ResponseValidator;
use App\Entity\Project;
use App\Entity\Slide;
use App\Slide\SlideBuilder;
use App\Slide\UnsupportedSlideTypeException;
use App\Tests\FunctionalTestCase;

/**
 * HRM-T158 — Integration tests verifying that no free LLM HTML reaches the final render.
 *
 * These tests use the real Symfony container so that SlideBuilder is wired with
 * the live Twig environment, the real templates, and the real SlideHtmlSanitizer.
 * ResponseValidator is also pulled from the container to exercise T155 end-to-end.
 */
final class SanitizationIntegrationTest extends FunctionalTestCase
{
    private SlideBuilder $slideBuilder;
    private ResponseValidator $responseValidator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slideBuilder = static::getContainer()->get(SlideBuilder::class);
        $this->responseValidator = static::getContainer()->get(ResponseValidator::class);
    }

    // ── T155 / T158 — ResponseValidator rejects unsupported slide types ────────

    public function testResponseValidatorRejectsUnsupportedSlideTypeFromLlm(): void
    {
        $this->expectException(ResponseValidationException::class);

        $this->responseValidator->validate(json_encode([
            'assistant_message' => 'Voici une slide avec un type injecté.',
            'actions' => [[
                'action' => 'add_slide',
                'slide' => [
                    'title' => 'Injection attempt',
                    'type' => 'injected_type',
                    'body' => '<script>alert("xss")</script>',
                ],
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    public function testResponseValidatorAcceptsValidSlideTypesOnly(): void
    {
        $validPayloads = [
            'title' => [
                'title' => 'Titre de test',
                'type' => 'title',
                'body' => 'Contenu normal',
            ],
            'content' => [
                'title' => 'Titre de test',
                'type' => 'content',
                'items' => ['Point A', 'Point B'],
            ],
            'closing' => [
                'title' => 'Titre de test',
                'type' => 'closing',
                'body' => 'Conclusion',
            ],
            'split' => [
                'title' => 'Titre de test',
                'type' => 'split',
                'body' => 'Contenu normal',
            ],
            'image' => [
                'title' => 'Titre de test',
                'type' => 'image',
            ],
            'quote' => [
                'title' => 'Titre de test',
                'type' => 'quote',
                'body' => 'Contenu normal',
            ],
            'timeline' => [
                'title' => 'Titre de test',
                'type' => 'timeline',
                'items' => [
                    ['label' => 'Étape 1', 'year' => '2020'],
                    ['label' => 'Étape 2', 'year' => '2024'],
                ],
            ],
            'stats' => [
                'title' => 'Titre de test',
                'type' => 'stats',
                'stats' => [
                    ['value' => '98%', 'label' => 'Satisfaction'],
                    ['value' => '10M', 'label' => 'Utilisateurs'],
                ],
            ],
            'comparison' => [
                'title' => 'Titre de test',
                'type' => 'comparison',
                'left' => ['heading' => 'Avant', 'items' => ['Lent']],
                'right' => ['heading' => 'Après', 'items' => ['Rapide']],
            ],
        ];

        foreach ($validPayloads as $type => $slideData) {
            $result = $this->responseValidator->validate(json_encode([
                'assistant_message' => 'Slide valide.',
                'actions' => [[
                    'action' => 'add_slide',
                    'slide' => $slideData,
                ]],
            ], JSON_THROW_ON_ERROR));

            self::assertSame($type, $result->actions()[0]['slide']['type']);
        }
    }

    // ── T156 / T158 — SlideBuilder blocks unsupported types at render time ─────

    public function testSlideBuilderBlocksUnsupportedTypeInjection(): void
    {
        $slide = $this->makeSlide('injected_type', ['title' => '<script>xss()</script>']);

        $this->expectException(UnsupportedSlideTypeException::class);

        $this->slideBuilder->buildSlide($slide);
    }

    // ── T154 / T158 — SlideBuilder sanitizes all LLM-originated text fields ───

    public function testSlideBuilderSanitizesTitleSlideFieldsBeforeRender(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TITLE, [
            'label' => '<script>alert("xss")</script>Label',
            'title' => '<img src=x onerror=alert(1)>Titre principal',
            'subtitle' => '<b onclick="evil()">Sous-titre</b>',
        ]);

        $html = $this->slideBuilder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('onerror', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringContainsString('Label', $html);
        self::assertStringContainsString('Titre principal', $html);
        self::assertStringContainsString('Sous-titre', $html);
    }

    public function testSlideBuilderSanitizesContentSlideFieldsBeforeRender(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_CONTENT, [
            'title' => '<svg onload="steal()">Chiffres clés</svg>',
            'body' => '<iframe src="https://evil.com"></iframe>Texte du corps',
            'items' => [
                '<script>document.location="https://evil.com"</script>Point 1',
                '<a href="javascript:evil()">Point 2</a>',
                'Point 3 propre',
            ],
        ]);

        $html = $this->slideBuilder->buildSlide($slide);

        self::assertStringNotContainsString('<svg', $html);
        self::assertStringNotContainsString('onload', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('Chiffres clés', $html);
        self::assertStringContainsString('Texte du corps', $html);
        self::assertStringContainsString('Point 1', $html);
        self::assertStringContainsString('Point 2', $html);
        self::assertStringContainsString('Point 3 propre', $html);
    }

    public function testSlideBuilderSanitizesQuoteSlideFieldsBeforeRender(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_QUOTE, [
            'quote' => '<object data="evil.swf">Grande citation</object>',
            'author' => '<b style="color:red">Auteur</b>',
            'role' => '<em>CEO</em>',
            'source' => '<a href="javascript:alert(1)">Source</a>',
        ]);

        $html = $this->slideBuilder->buildSlide($slide);

        self::assertStringNotContainsString('<object', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<em>', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('Grande citation', $html);
        self::assertStringContainsString('Auteur', $html);
        self::assertStringContainsString('CEO', $html);
        self::assertStringContainsString('Source', $html);
    }

    public function testSlideBuilderSanitizesTimelineSlideFieldsBeforeRender(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_TIMELINE, [
            'title' => '<script>xss()</script>Chronologie',
            'items' => [
                [
                    'year' => '<b>2020</b>',
                    'label' => '<em>Fondation</em>',
                    'description' => '<script>steal(document.cookie)</script>Démarrage.',
                ],
                [
                    'year' => '2024',
                    'label' => 'Expansion',
                    'description' => 'Croissance normale.',
                ],
            ],
        ]);

        $html = $this->slideBuilder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<em>', $html);
        self::assertStringContainsString('Chronologie', $html);
        self::assertStringContainsString('2020', $html);
        self::assertStringContainsString('Fondation', $html);
        self::assertStringContainsString('Démarrage.', $html);
    }

    public function testSlideBuilderSanitizesStatsSlideFieldsBeforeRender(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_STATS, [
            'title' => '<h1>Statistiques</h1>',
            'stats' => [
                [
                    'value' => '<strong onclick="evil()">98%</strong>',
                    'label' => '<em>Satisfaction</em>',
                    'detail' => '<script>xss()</script>Basé sur 1 000 réponses.',
                ],
            ],
        ]);

        $html = $this->slideBuilder->buildSlide($slide);

        self::assertStringNotContainsString('<h1>', $html);
        self::assertStringNotContainsString('<strong', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('<em>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('Statistiques', $html);
        self::assertStringContainsString('98%', $html);
        self::assertStringContainsString('Satisfaction', $html);
    }

    public function testSlideBuilderSanitizesComparisonSlideFieldsBeforeRender(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_COMPARISON, [
            'title' => '<script>xss()</script>Comparatif',
            'left' => [
                'heading' => '<b>Avant</b>',
                'items' => ['<script>evil()</script>Manuel', 'Lent'],
                'highlight' => '<em>Coûteux</em>',
            ],
            'right' => [
                'heading' => 'Après',
                'items' => ['Automatisé', 'Rapide'],
                'highlight' => 'Économique',
            ],
        ]);

        $html = $this->slideBuilder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<em>', $html);
        self::assertStringContainsString('Comparatif', $html);
        self::assertStringContainsString('Avant', $html);
        self::assertStringContainsString('Manuel', $html);
        self::assertStringContainsString('Après', $html);
    }

    public function testSlideBuilderSanitizesClosingSlideFieldsBeforeRender(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_CLOSING, [
            'message' => '<script>document.write("pwned")</script>Merci !',
            'cta_label' => '<b>Contactez-nous</b>',
            'cta_url' => 'https://harmony.test/contact',
        ]);

        $html = $this->slideBuilder->buildSlide($slide);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('Merci !', $html);
        self::assertStringContainsString('Contactez-nous', $html);
    }

    public function testSlideBuilderBlocksJavascriptUrlsInClosingSlide(): void
    {
        $slide = $this->makeSlide(Slide::TYPE_CLOSING, [
            'message' => 'Au revoir',
            'cta_label' => 'Cliquez ici',
            'cta_url' => 'javascript:alert("xss")',
        ]);

        $html = $this->slideBuilder->buildSlide($slide);

        self::assertStringNotContainsString('javascript:', $html);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $content
     */
    private function makeSlide(string $type, array $content, int $position = 1): Slide
    {
        $project = new Project();
        $project->setTitle('Projet de test');

        $slide = new Slide();
        $slide->setProject($project);
        $slide->setType($type);
        $slide->setContent($content);
        $slide->setPosition($position);

        return $slide;
    }
}
