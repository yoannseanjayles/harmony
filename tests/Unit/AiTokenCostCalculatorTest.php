<?php

namespace App\Tests\Unit;

use App\AI\AiTokenCostCalculator;
use PHPUnit\Framework\TestCase;

/**
 * HRM-F40 T329 — Unit tests for AiTokenCostCalculator (T326).
 */
final class AiTokenCostCalculatorTest extends TestCase
{
    private AiTokenCostCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AiTokenCostCalculator();
    }

    public function testReturnsZeroWhenBothTokenCountsAreNull(): void
    {
        self::assertSame(0.0, $this->calculator->calculate('gpt-4.1', null, null));
    }

    public function testReturnsZeroForUnknownModel(): void
    {
        self::assertSame(0.0, $this->calculator->calculate('unknown-model-xyz', 1000, 500));
    }

    public function testCalculatesCorrectCostForGpt41(): void
    {
        // gpt-4.1: $2.00/M input, $8.00/M output
        // 1000 input → 0.002, 500 output → 0.004 → total 0.006
        $cost = $this->calculator->calculate('gpt-4.1', 1000, 500);
        self::assertEqualsWithDelta(0.006, $cost, 0.0000001);
    }

    public function testCalculatesCorrectCostForGpt41Mini(): void
    {
        // gpt-4.1-mini: $0.40/M input, $1.60/M output
        // 2000 input → 0.0008, 1000 output → 0.0016 → total 0.0024
        $cost = $this->calculator->calculate('gpt-4.1-mini', 2000, 1000);
        self::assertEqualsWithDelta(0.0024, $cost, 0.0000001);
    }

    public function testCalculatesCorrectCostForClaude37(): void
    {
        // claude-3-7-sonnet: $3.00/M input, $15.00/M output
        // 500 input → 0.0015, 200 output → 0.003 → total 0.0045
        $cost = $this->calculator->calculate('claude-3-7-sonnet', 500, 200);
        self::assertEqualsWithDelta(0.0045, $cost, 0.0000001);
    }

    public function testHandlesNullInputTokensOnly(): void
    {
        // gpt-4.1: output only: 1000 output × $8.00/M → 0.008
        $cost = $this->calculator->calculate('gpt-4.1', null, 1000);
        self::assertEqualsWithDelta(0.008, $cost, 0.0000001);
    }

    public function testHandlesNullOutputTokensOnly(): void
    {
        // gpt-4.1: input only: 1000 input × $2.00/M → 0.002
        $cost = $this->calculator->calculate('gpt-4.1', 1000, null);
        self::assertEqualsWithDelta(0.002, $cost, 0.0000001);
    }

    public function testCostIsNeverNegative(): void
    {
        $cost = $this->calculator->calculate('gpt-4.1', 0, 0);
        self::assertSame(0.0, $cost);
    }
}
