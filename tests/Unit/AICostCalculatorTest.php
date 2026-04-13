<?php

namespace App\Tests\Unit;

use App\AI\AICostCalculator;
use PHPUnit\Framework\TestCase;

/**
 * T322 — Unit tests for AICostCalculator.
 */
final class AICostCalculatorTest extends TestCase
{
    private AICostCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AICostCalculator();
    }

    // ── OpenAI ───────────────────────────────────────────────────────────────

    public function testGpt41MiniCost(): void
    {
        // 1 000 input @ $0.40/M  = $0.00040
        // 500 output @ $1.60/M   = $0.00080
        // total                  = $0.00120
        $cost = $this->calculator->calculateUsd('gpt-4.1-mini', 1_000, 500);
        self::assertEqualsWithDelta(0.00120, $cost, 1e-9);
    }

    public function testGpt41Cost(): void
    {
        // 2 000 input @ $2.00/M  = $0.00400
        // 1 000 output @ $8.00/M = $0.00800
        // total                  = $0.01200
        $cost = $this->calculator->calculateUsd('gpt-4.1', 2_000, 1_000);
        self::assertEqualsWithDelta(0.01200, $cost, 1e-9);
    }

    // ── Anthropic ────────────────────────────────────────────────────────────

    public function testClaude37SonnetCost(): void
    {
        // 500 input @ $3.00/M   = $0.00150
        // 200 output @ $15.00/M = $0.00300
        // total                 = $0.00450
        $cost = $this->calculator->calculateUsd('claude-3-7-sonnet', 500, 200);
        self::assertEqualsWithDelta(0.00450, $cost, 1e-9);
    }

    public function testClaude35SonnetCost(): void
    {
        // 1 000 input @ $3.00/M  = $0.00300
        // 400 output @ $15.00/M  = $0.00600
        // total                  = $0.00900
        $cost = $this->calculator->calculateUsd('claude-3-5-sonnet', 1_000, 400);
        self::assertEqualsWithDelta(0.00900, $cost, 1e-9);
    }

    // ── Edge cases ───────────────────────────────────────────────────────────

    public function testUnknownModelReturnsZero(): void
    {
        $cost = $this->calculator->calculateUsd('unknown-model-x', 1_000, 500);
        self::assertSame(0.0, $cost);
    }

    public function testZeroTokensReturnsZero(): void
    {
        $cost = $this->calculator->calculateUsd('gpt-4.1-mini', 0, 0);
        self::assertSame(0.0, $cost);
    }

    public function testNegativeTokensAreClampedToZero(): void
    {
        $cost = $this->calculator->calculateUsd('gpt-4.1', -100, -50);
        self::assertSame(0.0, $cost);
    }

    public function testCustomRatesAreUsed(): void
    {
        $calculator = new AICostCalculator([
            'my-model' => ['input' => 10.00, 'output' => 20.00],
        ]);

        // 1 000 input @ $10/M  = $0.01000
        // 1 000 output @ $20/M = $0.02000
        // total                = $0.03000
        $cost = $calculator->calculateUsd('my-model', 1_000, 1_000);
        self::assertEqualsWithDelta(0.03000, $cost, 1e-9);
    }

    public function testDefaultRatesContainsAllSupportedModels(): void
    {
        $rates = AICostCalculator::defaultRates();
        $expectedModels = ['gpt-4.1-mini', 'gpt-4.1', 'claude-3-7-sonnet', 'claude-3-5-sonnet'];
        foreach ($expectedModels as $model) {
            self::assertArrayHasKey($model, $rates, "Rate missing for model: $model");
        }
    }
}
