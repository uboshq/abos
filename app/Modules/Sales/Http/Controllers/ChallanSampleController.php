<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

/**
 * ডেলিভারি চালানের নকশার নমুনা — ছাপার নিয়ন্ত্রণের কার্ড আর পপআপ ([[PaperSampleController]])।
 * ⓘ তথ্যের ঘর [[ChallanPaperFacts]]-এর সেই একই; QR-এর ঠিকানা শূন্য-UUID, খুললে কিছু পায় না।
 */
class ChallanSampleController extends PaperSampleController
{
    protected function paper(): string
    {
        return 'challan';
    }

    protected function target(): string
    {
        return 'challan';
    }

    protected function sample(): array
    {
        [$en, $bn] = $this->words('14700');

        return [
            'doc' => $this->doc(
                __('sales::doc.challan'),
                ['core.print.delivered_by', 'core.print.driver', 'core.print.received_by'],
                ['core.print.total' => '14,700.00'],
                $this->lines(),
            ),
            'facts' => [
                'no' => 'S-0000', 'date' => $this->today(), 'ship_date' => $this->today(), 'order_no' => 'SO-0000',
                'warehouse' => $this->s('point'), 'created_by' => $this->s('creator'),
                'to' => $this->party(),
                'transport' => ['carrier' => $this->s('carrier'), 'vehicle' => 'Truck DM-TA-11-0000', 'driver' => $this->s('creator'), 'driver_phone' => '01800-000000'],
                'items' => '2', 'total_qty' => '10 Ctn, 6 Bag', 'total' => '14,700.00',
                'words' => $en, 'words_bn' => $bn,
                'scan_url' => route('sales.scan', '00000000-0000-7000-8000-000000000000'),
            ],
        ];
    }
}
