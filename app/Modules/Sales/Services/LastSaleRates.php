<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\SalesInvoice;

/**
 * ⭐ "এই ক্রেতার শেষ দর" — নতুন কাউন্টারের নীল বাক্স, একক দরের ঘরের ঠিক উপরে (মালিকের অনুমোদিত নমুনা, ২৭–২৮ নভেম্বর ২০২৬;
 *   NEXUS-এর ধাপ A-র LastSaleRate-এর সাথে একই নিয়ম)।
 *
 * ⓘ প্রতিটা পণ্যের সবচেয়ে শেষ **পাকা** বিলের দর — খসড়া, অপেক্ষায় থাকা বা বাতিল বিল নয় ([[totals-count-only-final-papers]])।
 *   শেষ মানে বিলের তারিখ, তারপর নম্বর (একই দিনে দুটো হলে পরেরটা)। ⓘ কোম্পানির সীমা মডেলের নিজের (BelongsToCompany)।
 * ⓘ দর মূল এককে (সারির `rate`), সাথে বিলের নম্বর আর তারিখ — বাক্সে hover করলে পুরো বাক্য।
 */
final class LastSaleRates
{
    /** ⓘ যত পুরনো বিল দেখা হয় তার সীমা — শেষ দর খুঁজতে পুরো ইতিহাস লাগে না */
    private const LOOK_BACK = 200;

    /**
     * @return array<string, array{rate: string, onDate: string, invoiceNo: string}>
     */
    public function forCustomer(int $customerId): array
    {
        $rates = [];

        $invoices = SalesInvoice::query()
            ->where('customer_id', $customerId)
            ->posted()
            ->orderByDesc('trx_date')
            ->orderByDesc('id')
            ->limit(self::LOOK_BACK)
            ->with(['lines:id,sales_invoice_id,product_id,rate'])
            ->get(['id', 'document_no', 'trx_date']);

        foreach ($invoices as $invoice) {
            foreach ($invoice->lines as $line) {
                $key = (string) $line->product_id;

                if (isset($rates[$key]) || bccomp((string) $line->rate, '0', 4) <= 0) {
                    continue;
                }

                $rates[$key] = [
                    'rate' => bcadd((string) $line->rate, '0', 2),
                    'onDate' => (string) $invoice->trx_date?->format('d-m-Y'),
                    'invoiceNo' => (string) $invoice->document_no,
                ];
            }
        }

        return $rates;
    }
}
