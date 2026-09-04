<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use Illuminate\Support\Facades\DB;

/**
 * এই সরবরাহকারীর কাছ থেকে গতবার কত দরে কেনা হয়েছিল।
 *
 * ── মালিকের কাজটা একটাই, আর সেটা দরাদরি ─────────────────────────────
 * *"গতবার আপনি ৯৮-এ দিয়েছিলেন, আজ ১০৫ কেন?"*
 *
 * তাই সংখ্যাটার সাথে **তারিখটাও** যায়। তারিখ ছাড়া সংখ্যাটা তর্কে টেকে
 * না — গত সপ্তাহের দর আর ছয় মাস আগের দর এক জিনিস নয়, আর দ্বিতীয়টা
 * দিয়ে দরাদরি করতে গেলে সরবরাহকারীর কাছেই ধরা পড়তে হয়।
 *
 * ── ⛔ `products.purchase_price` কেন নয় ──────────────────────────────
 * ওই ঘরটা **কোম্পানি-ব্যাপী শেষ দর** — যে কারো কাছ থেকে। সেটার নিজের
 * কাজ আছে (বিক্রয়মূল্য ঠিক করার মাপকাঠি), আর সেটা এখানে ছোঁয়া হয় না।
 *
 * দুইটা আলাদা প্রশ্ন, আর মিশিয়ে ফেললে দরাদরির বাক্যটাই মিথ্যা হয়:
 * অন্য সরবরাহকারীর কাছ থেকে ৯৮-এ কেনা মালের দর দেখিয়ে **এই**
 * সরবরাহকারীকে চাপ দেওয়া যায় না।
 *
 * ── ⚠️ একবারে সবগুলো, সারি ধরে ধরে নয় ───────────────────────────────
 * সরবরাহকারী বাছার সাথে সাথেই পুরো তালিকাটা একবারে আসে। প্রতি সারিতে
 * একটা করে কোয়েরি হলে বিশ লাইনের কার্টে বিশটা রাউন্ড-ট্রিপ হত, আর
 * কাউন্টারে দাঁড়ানো মানুষ সেটা টের পান।
 */
final class LastPaidRate
{
    /**
     * এই সরবরাহকারীর কাছ থেকে প্রতিটা পণ্যের শেষ দর ও তারিখ।
     *
     * ⚠️ বাতিল হওয়া বিল বাদ। বাতিল মানে ঘটনাটা ঘটেনি — ওই দর দেখিয়ে
     * দরাদরি করতে গেলে সরবরাহকারী বলতেন "ওটা তো ফেরত গেছে", আর
     * কথাটা তাঁরই ঠিক হত।
     *
     * ⓘ খসড়া বিলও বাদ পড়ে না — [[DocumentStatus::POSTED]] ধরা হয় না
     * ইচ্ছাকৃতভাবে নয়, বরং খসড়া বিল এই পর্দা দিয়ে তৈরিই হয় না
     * ([[DirectPurchaseService]] সাথে সাথে নিশ্চিত করে)। শর্তটা তাই
     * "বাতিল নয়" — যা ভবিষ্যতের অন্য পথেও সত্যি থাকবে।
     *
     * @return array<int, array{rate: string, on: string}>  পণ্যের আইডি => দর ও তারিখ
     */
    public function forSupplier(int $supplierId): array
    {
        if ($supplierId <= 0) {
            return [];
        }

        /*
         * ⚠️ "শেষ" মানে তারিখে শেষ, বসানোয় শেষ নয় — আর দুইটা আলাদা।
         *
         * পুরনো তারিখের একটা বিল আজ বসানো হতেই পারে (কাগজ দেরিতে
         * এসেছে)। শুধু `max(id)` ধরলে ওই বিলটা "শেষ" হয়ে যেত, আর
         * পর্দায় ছয় মাস আগের দর "গতবার" নামে বসত।
         *
         * তাই ক্রম দুইটা কলামে: আগে তারিখ, তারপর আইডি। একই তারিখে
         * দুইটা বিল থাকলে পরে বসানোটাই শেষ — সেখানে আইডিই একমাত্র
         * উত্তর।
         */
        $latest = DB::table('pur_bill_lines as l')
            ->join('pur_bills as b', 'b.id', '=', 'l.purchase_bill_id')
            ->where('b.company_id', CompanyContext::id())
            ->where('b.supplier_id', $supplierId)
            ->whereNull('b.deleted_at')
            ->where('b.status', '<>', DocumentStatus::CANCELLED)
            ->selectRaw(
                'l.product_id, l.rate, b.trx_date, '
                .'row_number() over (partition by l.product_id '
                .'order by b.trx_date desc, b.id desc) as seq'
            );

        $rows = DB::query()->fromSub($latest, 'last_rates')->where('seq', 1)->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->product_id] = [
                'rate' => (string) $row->rate,
                'on' => (string) $row->trx_date,
            ];
        }

        return $out;
    }
}
