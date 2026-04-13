<?php

namespace App\AI;

/**
 * HRM-F40 T326 — Estimates the cost (USD) of a single AI generation call
 * from the input/output token counts returned by the provider.
 *
 * Rates are expressed in USD per million tokens (as published by each provider).
 * When a model is unknown the calculator returns 0.0 so that a missing entry
 * never breaks the generation flow.
 */
final class AiTokenCostCalculator
{
    /**
     * Per-model rates in USD per million tokens.
     * Format: [inputPerMillion, outputPerMillion]
     *
     * @var array<string, array{float, float}>
     */
    private const MODEL_RATES = [
        'gpt-4.1'           => [2.00, 8.00],
        'gpt-4.1-mini'      => [0.40, 1.60],
        'claude-3-7-sonnet' => [3.00, 15.00],
        'claude-3-5-sonnet' => [3.00, 15.00],
    ];

    /**
     * @param int|null $inputTokens  Number of prompt tokens consumed
     * @param int|null $outputTokens Number of completion tokens generated
     */
    public function calculate(string $model, ?int $inputTokens, ?int $outputTokens): float
    {
        if ($inputTokens === null && $outputTokens === null) {
            return 0.0;
        }

        $rates = self::MODEL_RATES[$model] ?? null;
        if ($rates === null) {
            return 0.0;
        }

        [$inputRate, $outputRate] = $rates;

        $cost = (($inputTokens ?? 0) * $inputRate + ($outputTokens ?? 0) * $outputRate) / 1_000_000;

        return round(max(0.0, $cost), 6);
    }
}
