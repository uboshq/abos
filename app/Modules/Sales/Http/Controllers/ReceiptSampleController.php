<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

/**
 * আদায়ের রসিদের নকশার নমুনা — ছাপার নিয়ন্ত্রণের কার্ড আর পপআপ ([[PaperSampleController]])।
 * ⓘ তথ্যের ঘর [[OrderPaperFacts::receipt()]]-এর সেই একই — দুই বিলে ভাগ করে জমা, যাতে "কোন বিলে বসল" ছকটা দেখা যায়।
 */
class ReceiptSampleController extends PaperSampleController
{
    protected function paper(): string
    {
        return 'receipt';
    }

    protected function target(): string
    {
        return 'receipt';
    }

    protected function sample(): array
    {
        [$en, $bn] = $this->words('12500');

        return [
            'doc' => $this->doc(__('sales::doc.collection'), ['core.print.received_by'], ['core.print.total' => '12,500.00']),
            'facts' => [
                'no' => 'COL-0000', 'date' => $this->today(),
                'from' => $this->party(),
                'account' => $this->s('method'), 'instrument' => 'cheque', 'instrument_no' => '000000', 'instrument_date' => $this->today(),
                'received_by' => $this->s('creator'),
                'bills' => [
                    ['no' => 'S-0000', 'date' => $this->today(), 'bill_total' => '14,500.00', 'amount' => '9,500.00'],
                    ['no' => 'S-0001', 'date' => $this->today(), 'bill_total' => '3,000.00', 'amount' => '3,000.00'],
                ],
                'total' => '12,500.00', 'words' => $en, 'words_bn' => $bn, 'narration' => '',
            ],
        ];
    }
}
