<?php

namespace App\AI\Http;

final class FakeAIHttpClient implements AIHttpClientInterface
{
    public function postJson(string $url, array $headers, array $payload): HttpResponse
    {
        $model = (string) ($payload['model'] ?? 'fake-model');

        if (str_contains($url, 'anthropic')) {
            $userMessage = $this->extractAnthropicUserMessage($payload);
            $responseContent = $this->buildResponsePayload('Anthropic', $userMessage);

            return new HttpResponse(200, json_encode([
                'id' => 'msg_fake_123',
                'model' => $model,
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($responseContent, JSON_THROW_ON_ERROR),
                ]],
                'usage' => [
                    'input_tokens' => 40,
                    'output_tokens' => 18,
                ],
            ], JSON_THROW_ON_ERROR));
        }

        $userMessage = $this->extractOpenAIUserMessage($payload);
        $responseContent = $this->buildResponsePayload('OpenAI', $userMessage);

        return new HttpResponse(200, json_encode([
            'id' => 'chatcmpl_fake_123',
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode($responseContent, JSON_THROW_ON_ERROR),
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 42,
                'completion_tokens' => 20,
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponsePayload(string $providerLabel, string $userMessage): array
    {
        if ($this->requestsDeckGeneration($userMessage)) {
            return [
                'assistant_message' => sprintf('Reponse Harmony mock (%s): %s', $providerLabel, $userMessage),
                'actions' => [
                    [
                        'action' => 'add_slide',
                        'position' => 1,
                        'slide' => [
                            'id' => 'slide-vision-du-lancement',
                            'title' => 'Vision du lancement',
                            'type' => 'bullet_list',
                            'items' => ['Promesse centrale du produit', 'Impact attendu sur le marche'],
                        ],
                    ],
                    [
                        'action' => 'add_slide',
                        'position' => 2,
                        'slide' => [
                            'id' => 'slide-probleme-client',
                            'title' => 'Probleme client',
                            'type' => 'bullet_list',
                            'items' => ['Douleur principale traitee', "Exemple concret d'usage"],
                        ],
                    ],
                    [
                        'action' => 'add_slide',
                        'position' => 3,
                        'slide' => [
                            'id' => 'slide-proposition-de-valeur',
                            'title' => 'Proposition de valeur',
                            'type' => 'bullet_list',
                            'items' => ['Benefice cle', 'Differenciation immediate'],
                        ],
                    ],
                    [
                        'action' => 'add_slide',
                        'position' => 4,
                        'slide' => [
                            'id' => 'slide-plan-go-to-market',
                            'title' => 'Plan go-to-market',
                            'type' => 'bullet_list',
                            'items' => ['Canaux prioritaires', 'Cadence de lancement'],
                        ],
                    ],
                    [
                        'action' => 'add_slide',
                        'position' => 5,
                        'slide' => [
                            'id' => 'slide-prochaines-etapes',
                            'title' => 'Prochaines etapes',
                            'type' => 'bullet_list',
                            'items' => ['Decisons a prendre', 'Actions sur 30 jours'],
                        ],
                    ],
                ],
            ];
        }

        if ($this->requestsDeckReorderConfirmation($userMessage)) {
            return [
                'assistant_message' => 'Je vous propose de reordonner les slides pour mettre la synthese avant l\'introduction. Confirmez-vous ?',
                'actions' => [[
                    'action' => 'request_confirmation',
                    'summary' => 'Reordonner les slides pour placer la synthese en premier.',
                    'proposed_actions' => [[
                        'action' => 'reorder_slides',
                        'slide_ids' => ['slide-2', 'slide-1', 'slide-3'],
                    ]],
                ]],
            ];
        }

        return [
            'assistant_message' => sprintf('Reponse Harmony mock (%s): %s', $providerLabel, $userMessage),
            'actions' => [],
        ];
    }

    private function requestsDeckGeneration(string $userMessage): bool
    {
        return preg_match('/\b(?:5|cinq)\s+slides?\b/i', $userMessage) === 1;
    }

    private function requestsDeckReorderConfirmation(string $userMessage): bool
    {
        return preg_match('/\b(?:reorganise|reorganiser|reordonne|reordonner|inverse)\b/i', $userMessage) === 1
            && preg_match('/\bslides?\b/i', $userMessage) === 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractOpenAIUserMessage(array $payload): string
    {
        $messages = $payload['messages'] ?? [];
        if (!is_array($messages) || $messages === []) {
            return 'Message vide';
        }

        $lastMessage = end($messages);
        if (!is_array($lastMessage)) {
            return 'Message vide';
        }

        return (string) ($lastMessage['content'] ?? 'Message vide');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractAnthropicUserMessage(array $payload): string
    {
        $messages = $payload['messages'] ?? [];
        if (!is_array($messages) || $messages === []) {
            return 'Message vide';
        }

        $lastMessage = end($messages);
        if (!is_array($lastMessage)) {
            return 'Message vide';
        }

        $content = $lastMessage['content'] ?? [];
        if (!is_array($content) || $content === []) {
            return 'Message vide';
        }

        $firstBlock = $content[0] ?? [];
        if (!is_array($firstBlock)) {
            return 'Message vide';
        }

        return (string) ($firstBlock['text'] ?? 'Message vide');
    }
}
