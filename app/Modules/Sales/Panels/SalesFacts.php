<?php

declare(strict_types=1);

namespace App\Modules\Sales\Panels;

use App\Core\Contracts\ContributesFacts;
use App\Core\Panels\Fact;
use App\Core\Support\DateFormat;
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
            ->posted()
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

            /*
             * কয়টা চালান — আর সেখানে যাওয়ার পথ।
             *
             * ── কেন সংখ্যাটার সাথে ঠিকানাও ──────────────────────────
             * ওডুর রেকর্ড-পাতায় এই ঘরটাই "smart button": বড় করে সংখ্যা,
             * নিচে নাম, আর ক্লিক করলে ওই তালিকা। ঠিকানা ছাড়া ওটা কেবল
             * একটা সংখ্যা — আর তখন মানুষকে নিজে গিয়ে তালিকায় ছাঁকনি
             * বসাতে হয়, অর্থাৎ জিনিসটার আসল কাজটাই হয় না।
             *
             * বাকি রূপে ঠিকানাটা নষ্ট হয় না; সেখানে সংখ্যাটা তথ্যের
             * সারিতে বসে, আর লেখাটাই লিংক হয়।
             *
             * ── বাতিলগুলো গোনা হয় না ────────────────────────────────
             * `posted()` কেবল দাখিল হওয়া চালান নেয়, ঠিক যেমন উপরের
             * "শেষ কেনা"। তালিকাটাও ডিফল্টে বাতিল লুকায়, তাই সংখ্যা আর
             * তালিকা একই কথা বলে — দুই জায়গায় দুই উত্তর হলে সেটাই
             * সবচেয়ে খারাপ।
             */
            new Fact(
                label: 'sales::field.invoice_count',
                value: (string) SalesInvoice::query()
                    ->where('customer_id', $id)
                    ->posted()
                    ->count(),
                url: route('sales.invoice.index', ['customer' => $id]),
                sort: 20,
            ),
        ];
    }
}
