<?php

namespace Tests\Unit;

use App\Domain\Billing\Services\CustomPlanQuoteCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CustomPlanQuoteCalculatorTest extends TestCase
{
    #[DataProvider('publishedPlanInputs')]
    public function test_published_plan_inputs_reproduce_the_exact_public_price(int $customers, int $locations, int $expected): void
    {
        $quote = (new CustomPlanQuoteCalculator)->calculate([
            'monthly_customers' => $customers,
            'locations' => $locations,
            'messages_per_customer' => 2,
            'sms_segments_per_message' => 1,
            'mms_percent' => 0,
            'support_level' => 'standard',
        ]);

        $this->assertSame($expected, $quote['monthly_price_minor']);
        $this->assertSame($expected * 10, $quote['annual_price_minor']);
        $this->assertGreaterThan(0, $quote['gross_profit_minor']);
        $this->assertSame($customers * 2, $quote['usage']['messages']);
    }

    public static function publishedPlanInputs(): array
    {
        return [
            'Launch' => [100, 1, 4900],
            'Momentum' => [300, 1, 9900],
            'Expansion' => [400, 2, 13900],
        ];
    }

    public function test_message_heavy_quotes_are_raised_to_protect_the_target_margin(): void
    {
        $quote = (new CustomPlanQuoteCalculator)->calculate([
            'monthly_customers' => 100,
            'locations' => 1,
            'messages_per_customer' => 2,
            'monthly_messages' => 1000,
            'sms_segments_per_message' => 1,
            'mms_percent' => 0,
            'support_level' => 'standard',
        ]);

        $this->assertSame(1000, $quote['usage']['messages']);
        $this->assertSame(6000, $quote['monthly_price_minor']);
        $this->assertGreaterThanOrEqual(65, $quote['gross_margin_percent']);
    }
}
