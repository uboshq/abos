<?php

declare(strict_types=1);

namespace App\Modules\Sales\Panels;

use App\Core\Contracts\ContributesFacts;
use App\Core\Panels\Fact;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\SalesInvoice;

/**
 * গ্রাহক সম্পর্কে বিক্রয়ের যা বলার আছে।
 *
 * ── কেন এটা Sales-এ, Customer-এ নয় ──────────────────────────────────
 * আগে `Customer::lastPurchaseOn()` নিজেই `SalesInvoice` খুঁজত। ফলে
 * Customer দাঁড়াত Sales-এর উপর, আর Sales দাঁড়াত Customer-এর উপর — একটা
 * চক্র। বিক্রয় বন্ধ থাকা কোনো প্রতিষ্ঠানে গ্রাহকের পাতাটাই খুলত না।
 *
 * "শেষ কেনা কবে" কথাটা আসলে বিক্রয়ের; গ্রাহক কেবল তার বিষয়। তাই
 * উত্তরটা এখান থেকে যায়, আর গ্রাহকের পাতা জানেও না কে দিল।
 */
final class SalesFacts implements ContributesFacts
{
    /**
     * @return list<Fact>
     */
    public static function factsFor(string $entity, int $id): array
    {
        if ($entity !== 'customer') {
            return [];
        }

        /*
         * খাতায় বসা বিল ধরে, খসড়া নয়।
         *
         * খসড়া বিল কেনা নয়, লেখা। ছয় মাস চুপ থাকা গ্রাহকের সারিতে
         * গতকালের তারিখ দেখালে কেউ তাঁকে ফোন করত না।
         */
        $date = SalesInvoice::query()
            ->where('customer_id', $id)
            ->whereIn('status', [DocumentStatus::CONFIRMED, DocumentStatus::CLOSED])
            ->max('trx_date');

        if ($date === null) {
            return [];
        }

        return [
            new Fact(
                label: 'sales::field.last_purchase',
                value: DateFormat::format($date),
                sort: 10,
            ),
        ];
    }
}
