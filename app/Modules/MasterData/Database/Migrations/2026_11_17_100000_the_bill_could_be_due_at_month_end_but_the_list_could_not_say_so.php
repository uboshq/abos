<?php

declare(strict_types=1);

use App\Modules\MasterData\Models\PaymentTerm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * পরিশোধের শর্তে দুইটা নতুন — মাস শেষে, বছর শেষে।
 *
 * ── ⭐ মালিকের নির্দেশ, ১৬ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"পরিশোধের শর্ত ড্রপডাউনে আরও ২টি — month closing, yearly closing"*।
 *
 * ── ⚠️ বীজে যোগ করাই যথেষ্ট নয় ──────────────────────────────────────
 * [[App\Modules\MasterData\Services\MasterListService]]-এর তালিকায়
 * দুইটা যোগ করা হয়েছে, কিন্তু সেই `seed()` শুরুতেই ফিরে যায় যদি
 * টেবিলে **একটাও সারি থাকে**:
 *
 *     if ($model::query()->exists()) { return 0; }
 *
 * ⛔ অর্থাৎ ওটা কেবল **নতুন কোম্পানির** জন্য। যাঁরা আজ চালাচ্ছেন
 * (ADI, Demo) তাঁদের তালিকায় দুইটা কোনোদিন আসত না, আর কেউ বুঝতেও
 * পারত না কেন — বীজে তো লেখাই আছে।
 *
 * ⭐ তাই এই মাইগ্রেশনটা **চলতি প্রতিটা কোম্পানিতে** সারি দুইটা বসায়।
 *
 * ── ⓘ কেন `days` শূন্য ───────────────────────────────────────────────
 * বাকি শর্তগুলো বলে "বিলের N দিন পরে"। ⛔ "মাসের শেষ" N দিন পরে নয়,
 * একটা **ঘটনা** — ১ তারিখের বিলে ৩০ দিন, ২৮ তারিখের বিলে ২ দিন।
 * ⚠️ `days => 30` বসালে নামটা ঠিক দেখাত আর তারিখ নীরবে ভুল হত।
 *
 * ⭐ শূন্যের বাড়তি লাভ: ক্রয়ের পর্দা `days <= 0` সারি বাদ দেয়, তাই
 * ওখানে "০ দিনের বাকি" নামে অর্থহীন বিকল্প তৈরি হয় না।
 *
 * ── ⓘ পুরনো কিছু বদলায় না ───────────────────────────────────────────
 * মেপে দেখা: `suppliers.payment_term_id` কেবল রাখা ও দেখানো হয়, কোনো
 * দেয়-তারিখ এটা থেকে হিসাব হয় না। তাই নতুন সারি কোনো পুরনো বিলের
 * হিসাব ছোঁয় না।
 */
return new class extends Migration
{
    /** @var list<array{code: string, en: string, bn: string}> */
    private const TERMS = [
        ['code' => 'MONTH_END', 'en' => 'Month closing', 'bn' => 'মাস শেষে'],
        ['code' => 'YEAR_END', 'en' => 'Yearly closing', 'bn' => 'বছর শেষে'],
    ];

    public function up(): void
    {
        /*
         * ⚠️ `withoutGlobalScopes()` — এই মডেলে `BelongsToCompany` আছে,
         * আর মাইগ্রেশন চলার সময় কোনো "চলতি কোম্পানি" নেই। ⛔ স্কোপটা
         * না তুললে খোঁজাটা **প্রতিটা কোম্পানিতেই খালি** ফিরত, আর
         * মাইগ্রেশন প্রতিবার চালালে সারি দুইটা করে বাড়ত।
         */
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (self::TERMS as $term) {
                $exists = PaymentTerm::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('code', $term['code'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                /*
                 * ⓘ Eloquent দিয়ে বসানো, `DB::table()` দিয়ে নয় — তাতে
                 * `public_id` নিজে থেকে বসে ([[App\Core\Concerns\HasPublicId]])।
                 * ⚠️ কাঁচা insert-এ ওটা খালি থাকত, আর drill-এর পথগুলো
                 * ঐ ঘরটার উপর দাঁড়িয়ে আছে।
                 */
                $row = new PaymentTerm;
                $row->forceFill([
                    'company_id' => $companyId,
                    'code' => $term['code'],
                    'name_en' => $term['en'],
                    'name_bn' => $term['bn'],
                    'days' => 0,
                    'early_discount_percent' => 0,
                    'early_discount_days' => 0,
                    'is_default' => false,
                    'is_active' => true,
                ]);
                $row->save();
            }
        }
    }

    public function down(): void
    {
        /*
         * ⚠️ নরম-মোছা নয়, সত্যিকারের মোছা — ⓘ এই সারিগুলো এই
         * মাইগ্রেশনেরই বসানো, তাই ফেরানোর অর্থ ওদের না-থাকা।
         *
         * ⛔ কেউ যদি ইতিমধ্যে কোনো সরবরাহকারীকে এই শর্তে বসিয়ে থাকেন,
         * তাঁর ঘরটা খালি হয়ে যাবে। ⓘ সেটাই সঠিক: শর্তটা আর নেই।
         */
        PaymentTerm::query()
            ->withoutGlobalScopes()
            ->whereIn('code', array_column(self::TERMS, 'code'))
            ->forceDelete();
    }
};
