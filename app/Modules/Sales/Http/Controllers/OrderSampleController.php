<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

/**
 * বিক্রয় আদেশের নকশার নমুনা — ছাপার নিয়ন্ত্রণের কার্ড আর পপআপ ([[PaperSampleController]])।
 * ⓘ তথ্যের ঘর [[OrderPaperFacts::order()]]-এর সেই একই; টাকার সারি কন্ট্রোলারের `totals()`-এর ক্রমে।
 */
class OrderSampleController extends PaperSampleController
{
    protected function paper(): string
    {
        return 'order';
    }

    protected function target(): string
    {
        return 'order';
    }

    protected function sample(): array
    {
        [$en, $bn] = $this->words('14500');

        return [
            'doc' => $this->doc(
                __('sales::doc.order'),
                ['core.print.prepared_by', 'core.print.approved_by'],
                ['core.print.subtotal' => '14,700.00', 'core.print.discount' => '200.00', 'core.print.total' => '14,500.00'],
                $this->lines(),
            ),
            'facts' => [
                'no' => 'SO-0000', 'date' => $this->today(), 'deliver_on' => $this->today(), 'quotation_no' => 'QT-0000',
                'warehouse' => $this->s('point'), 'created_by' => $this->s('creator'),
                'to' => $this->party(),
                'items' => '2', 'total_qty' => '10 Ctn, 6 Bag', 'total' => '14,500.00',
                'words' => $en, 'words_bn' => $bn,
            ],
        ];
    }
}
