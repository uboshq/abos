<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Sales\Models\DeliveryChallan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * এই লটটা কাদের কাছে গেছে — রিকলের উত্তর।
 *
 * ── কেন এটা Sales-এ, Inventory-তে নয় ─────────────────────────────────
 * প্রথমে Inventory-তে লিখেছিলাম, কারণ প্রশ্নটা লট নিয়ে। কিন্তু উত্তরটা
 * **গ্রাহকের তালিকা**, আর গ্রাহক ও চালান দুইটাই Sales-এর জিনিস।
 * Inventory থেকে ওগুলোর নাম নিলে সীমানার পাহারা ধরত: module.php-তে
 * Inventory ঘোষণা করেছে সে master_data আর accounts-এর উপর দাঁড়ায়,
 * Sales-এর উপর নয়। উল্টো দিকে Sales আগে থেকেই Inventory-র উপর দাঁড়ায়,
 * তাই এখান থেকে দুই মডিউলের টেবিলই ছোঁয়া যায়।
 *
 * ── কেন চলাচল থেকে, বিক্রয়ের লাইন থেকে নয় ────────────────────────────
 * লট কোথায় গেছে সেটা লেখা আছে চলাচলের সারিতে — যেখানে FEFO সিদ্ধান্তটা
 * নিয়েছিল। লাইন থেকে গুনতে গেলে আজকের FEFO আবার চলত, আর উত্তরটা হত
 * "আজ হলে কোন লট যেত", অথচ প্রশ্ন "সেদিন কোন লট গিয়েছিল"।
 */
final class BatchTrace
{
    /**
     * লটটা কোন কোন চালানে গেছে, আর কার কাছে।
     *
     * @return Collection<int, object>
     */
    public function recipients(Batch $batch): Collection
    {
        return DB::table('inv_stock_movements as m')
            /*
             * টেবিলের নামটা মডেল থেকে, হাতে লেখা নয়।
             *
             * প্রথমে `sal_delivery_challans` লিখেছিলাম — যুক্তিসঙ্গত
             * অনুমান, কিন্তু ভুল; আসল নাম `sal_challans`। মডেলটাই
             * একমাত্র জায়গা যেখানে নামটা সত্যি।
             */
            ->join((new DeliveryChallan)->getTable().' as c', function ($join) {
                $join->on('c.id', '=', 'm.source_id')
                    ->where('m.source_type', '=', DeliveryChallan::STOCK_SOURCE);
            })
            ->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('m.company_id', CompanyContext::id())
            ->where('m.batch_id', $batch->id)

            // কেবল বেরিয়ে যাওয়া সারি, ফেরার সারি নয়
            ->where('m.floor_change', '<', 0)

            /*
             * বাতিল হওয়া চালান বাদ।
             *
             * ── প্রথমে শুধু ঋণাত্মক সারি বাদ দিয়েছিলাম, আর সেটা যথেষ্ট
             *    ছিল না ─────────────────────────────────────────────
             * বাতিলে ABOS একটা উল্টো সারি লেখে (`:cancel`, ধনাত্মক),
             * মূল সারিটা মুছে দেয় না — খাতা মোছা হয় না, এটাই নিয়ম।
             * ফলে ঋণাত্মক সারিটা থেকেই যায়, আর তালিকায় এমন একজন
             * গ্রাহক থাকতেন যাঁর কাছে মালটা কখনো পৌঁছায়ইনি। রিকলে
             * তাঁকে ফোন করা হত, আর সত্যিকারের ক্রেতাদের তালিকাটাই
             * অবিশ্বাস্য হয়ে যেত।
             */
            ->where('c.status', '<>', DocumentStatus::CANCELLED)
            ->orderByDesc('c.trx_date')
            ->orderByDesc('c.id')
            ->select([
                'c.id as challan_id',
                'c.document_no',
                'c.trx_date',
                'cu.name_en as customer_en',
                'cu.name_bn as customer_bn',
                'cu.id as customer_id',
                'cu.phone as customer_phone',
                DB::raw('ABS(m.floor_change) as qty'),
            ])
            ->get();
    }

    /**
     * এখনো তাকে কতটা আছে — রিকলে যেটুকু আটকানো যাবে।
     *
     * গ্রাহকের কাছে চলে যাওয়া মাল ফেরত আনা যায় না, কিন্তু তাকেরটা
     * এখনই আটকানো যায়। দুইটা সংখ্যা আলাদা করে দেখানো হয়, কারণ দুইটার
     * করণীয় আলাদা: একটায় ফোন, অন্যটায় হাত।
     */
    public function onHand(Batch $batch): string
    {
        return $batch->balance();
    }
}
