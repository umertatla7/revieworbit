<?php

namespace App\Domain\Billing\Services;

class CustomPlanQuoteCalculator
{
    private const PLATFORM_FEE_MINOR = 2400;

    private const CUSTOMER_BLOCK_SIZE = 100;

    private const FIRST_FOUR_BLOCKS_MINOR = 2500;

    private const VOLUME_BLOCK_MINOR = 2000;

    private const EXTRA_LOCATION_MINOR = 1500;

    /**
     * Produce an internal planning estimate, not a binding customer quote.
     * Monetary values are returned in USD cents.
     *
     * @param  array{monthly_customers:int,locations:int,messages_per_customer:int,monthly_messages?:int|null,sms_segments_per_message:int,mms_percent:int,support_level:string}  $input
     * @return array<string, mixed>
     */
    public function calculate(array $input): array
    {
        $customers = $input['monthly_customers'];
        $locations = $input['locations'];
        $messagesPerCustomer = $input['messages_per_customer'];
        $segmentsPerSms = $input['sms_segments_per_message'];
        $mmsPercent = $input['mms_percent'];
        $supportLevel = $input['support_level'];

        $blocks = (int) ceil($customers / self::CUSTOMER_BLOCK_SIZE);
        $firstFourBlocks = min(4, $blocks);
        $volumeBlocks = max(0, $blocks - 4);
        $customerCapacityMinor = ($firstFourBlocks * self::FIRST_FOUR_BLOCKS_MINOR)
            + ($volumeBlocks * self::VOLUME_BLOCK_MINOR);
        $locationMinor = max(0, $locations - 1) * self::EXTRA_LOCATION_MINOR;
        $supportMinor = match ($supportLevel) {
            'priority' => 2500,
            'dedicated' => 7500,
            default => 0,
        };
        $basePriceMinor = self::PLATFORM_FEE_MINOR + $customerCapacityMinor + $locationMinor + $supportMinor;

        $messages = $input['monthly_messages'] ?? null;
        $messages = is_int($messages) && $messages > 0 ? $messages : $customers * $messagesPerCustomer;
        $mmsMessages = (int) round($messages * ($mmsPercent / 100));
        $smsMessages = max(0, $messages - $mmsMessages);
        $smsSegments = $smsMessages * $segmentsPerSms;

        // Current planning assumptions: 1.27 cents/SMS segment and 3.05 cents/MMS,
        // including an average US carrier surcharge. They remain deliberately
        // visible in the response so admins know this is an estimate.
        $smsCostMinor = (int) round($smsSegments * 1.27);
        $mmsCostMinor = (int) round($mmsMessages * 3.05);
        $numberCostMinor = $locations * 115;
        $campaignCostMinor = 200;
        $providerCostMinor = $smsCostMinor + $mmsCostMinor + $numberCostMinor + $campaignCostMinor;
        $operationsAllowanceMinor = 300;

        // Raise unusually message-heavy custom quotes enough to preserve an
        // estimated 65% gross margin after Stripe. Normal published-plan usage
        // remains anchored to the catalogue prices above.
        $minimumMarginPriceMinor = (int) (ceil((($providerCostMinor + $operationsAllowanceMinor + 30) / 0.321) / 500) * 500);
        $monthlyPriceMinor = max($basePriceMinor, $minimumMarginPriceMinor);
        $stripeCostMinor = (int) round($monthlyPriceMinor * 0.029) + 30;
        $totalCostMinor = $providerCostMinor + $stripeCostMinor + $operationsAllowanceMinor;
        $grossProfitMinor = $monthlyPriceMinor - $totalCostMinor;

        return [
            'currency' => 'USD',
            'monthly_price_minor' => $monthlyPriceMinor,
            'annual_price_minor' => $monthlyPriceMinor * 10,
            'gross_profit_minor' => $grossProfitMinor,
            'gross_margin_percent' => $monthlyPriceMinor > 0 ? round(($grossProfitMinor / $monthlyPriceMinor) * 100, 1) : 0,
            'estimated_monthly_cost_minor' => $totalCostMinor,
            'one_time_a2p_registration_minor' => 1950,
            'usage' => [
                'monthly_customers' => $customers,
                'messages' => $messages,
                'sms_messages' => $smsMessages,
                'sms_segments' => $smsSegments,
                'mms_messages' => $mmsMessages,
                'locations' => $locations,
            ],
            'breakdown' => [
                'platform_fee_minor' => self::PLATFORM_FEE_MINOR,
                'customer_capacity_minor' => $customerCapacityMinor,
                'additional_locations_minor' => $locationMinor,
                'support_minor' => $supportMinor,
                'base_package_price_minor' => $basePriceMinor,
                'minimum_margin_price_minor' => $minimumMarginPriceMinor,
                'twilio_sms_minor' => $smsCostMinor,
                'twilio_mms_minor' => $mmsCostMinor,
                'twilio_numbers_minor' => $numberCostMinor,
                'a2p_campaign_minor' => $campaignCostMinor,
                'stripe_minor' => $stripeCostMinor,
                'operations_allowance_minor' => $operationsAllowanceMinor,
            ],
            'recommended_allowances' => $this->allowances($customers, $locations),
            'assumptions' => [
                'SMS is estimated at $0.0127 per segment, including an average US carrier fee.',
                'MMS is estimated at $0.0305 per message, including an average US carrier fee.',
                'Each location has one $1.15 monthly Twilio number; one low-volume A2P campaign is budgeted at $2 monthly.',
                'Stripe is estimated at 2.9% + $0.30 and operations at $3 per account monthly.',
                'Actual carrier mix, message encoding, failed sends, taxes, support, and negotiated rates can change the final margin.',
            ],
        ];
    }

    /** @return array<string, int> */
    private function allowances(int $customers, int $locations): array
    {
        if ($customers <= 100 && $locations === 1) {
            return ['template_limit' => 5, 'automation_limit' => 2, 'automation_step_limit' => 2, 'media_template_limit' => 1, 'review_destination_limit' => 1];
        }
        if ($customers <= 300 && $locations === 1) {
            return ['template_limit' => 5, 'automation_limit' => 8, 'automation_step_limit' => 4, 'media_template_limit' => 5, 'review_destination_limit' => 1];
        }

        return [
            'template_limit' => max(15, (int) ceil($customers / 100) * 3),
            'automation_limit' => max(25, (int) ceil($customers / 100) * 5),
            'automation_step_limit' => 4,
            'media_template_limit' => max(15, (int) ceil($customers / 100) * 3),
            'review_destination_limit' => $locations,
        ];
    }
}
