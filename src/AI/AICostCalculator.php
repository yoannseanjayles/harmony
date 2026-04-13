<?php

namespace App\AI;

/**
 * T320 — Calculates the estimated cost (USD) of an AI generation
 * based on provider-specific per-token rates.
 *
 * Rates are expressed in USD per 1 000 000 tokens (per-million).
 * Defaults follow public pricing at the time of implementation and can be
 * overridden via constructor injection (e.g. from container parameters).
 *
 * @phpstan-type RateMap array<string, array{input: float, output: float}>
 */
final class AICostCalculator
{
    /**
     * Default rates in USD per 1 000 000 tokens, keyed by model slug.
     *
     * @var RateMap
     */
    private array $rates;

    /**
     * @param RateMap|null $rates Custom per-model rates; when null the built-in defaults are used.
     */
    public function __construct(?array $rates = null)
    {
        $this->rates = $rates ?? self::defaultRates();
    }

    /**
     * Calculates the estimated cost in USD for a single generation call.
     *
     * Returns 0.0 when no rate is known for the given model or when token
     * counts are non-positive.
     */
    public function calculateUsd(string $model, int $inputTokens, int $outputTokens): float
    {
        $rate = $this->rates[$model] ?? null;
        if ($rate === null) {
            return 0.0;
        }

        $input  = max(0, $inputTokens);
        $output = max(0, $outputTokens);

        return ($input * $rate['input'] + $output * $rate['output']) / 1_000_000;
    }

    /**
     * Returns the default rate map.
     *
     * @return RateMap
     */
    public static function defaultRates(): array
    {
        return [
            // OpenAI
            'gpt-4.1-mini' => ['input' => 0.40,  'output' => 1.60],
            'gpt-4.1'      => ['input' => 2.00,  'output' => 8.00],
            // Anthropic
            'claude-3-7-sonnet' => ['input' => 3.00, 'output' => 15.00],
            'claude-3-5-sonnet' => ['input' => 3.00, 'output' => 15.00],
        ];
    }
}
