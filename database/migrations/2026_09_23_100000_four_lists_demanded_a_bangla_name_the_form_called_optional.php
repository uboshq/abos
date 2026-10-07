<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * চারটা তালিকা বাংলা নাম **দাবি** করত, অথচ ফর্ম বলত ঐচ্ছিক।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * `MasterListController`-এর যাচাইয়ে `name_bn` সবসময়ই `nullable`, আর
 * ৪ আগস্টের মূল মাস্টার-ডাটা মাইগ্রেশনে ঘরগুলোও `->nullable()`।
 *
 * কিন্তু পরে যোগ হওয়া চারটা টেবিলে `->nullable()` লিখতে ভুল হয়েছে —
 * `mdm_payment_methods` (৩০ আগস্ট), `acc_cost_centers` (৯ সেপ্টেম্বর),
 * `mdm_brands` ও `mdm_product_categories`। তাই ২৫টা টেবিলের ২১টা এক
 * কথা বলত আর চারটা উল্টো।
 *
 * ── দামটা আজ দেখা গেছে ───────────────────────────────────────────────
 * ২৫ আগস্ট ২০২৬, বিকেল ৫:০৯ — মালিক লাইভে "bKash" পেমেন্ট মেথডটা
 * যোগ করতে গিয়ে বাংলা নামের ঘরটা খালি রাখলেন, কারণ ফর্মে ওটার পাশে
 * তারকা চিহ্ন নেই। ফর্ম মেনে নিল, তারপর ইনসার্টে ৫০০।
 *
 * লগে **পাঁচবার** — চারবার পেমেন্ট মেথডে, একবার অন্য তালিকায়। অর্থাৎ
 * তিনি বারবার চেষ্টা করেছেন, আর প্রতিবারই পর্দা ভেঙেছে। আসল ব্যবসার
 * প্রথম দিনে।
 *
 * ── কেন ঘর বদলানো হলো, যাচাই নয় ──────────────────────────────────────
 * উল্টোটাও করা যেত — যাচাইয়ে `required` বসিয়ে। করা হয়নি, দুই কারণে:
 *
 * ১ · **বাকি একুশটা টেবিল ইতিমধ্যেই nullable।** চারটাকে কড়া করার
 *     মানে হত একই ফর্মে একেক তালিকায় একেক নিয়ম, আর ব্যবহারকারীর
 *     পক্ষে বোঝার কোনো উপায় নেই কোনটা কখন।
 *
 * ২ · **নামটা এমনিতেই ফিরে আসে।** `IsMasterRecord::name()` বাংলা না
 *     থাকলে ইংরেজিটা দেখায় (§১৮.৩)। তাই খালি ঘরের কোনো দৃশ্যমান
 *     ক্ষতি নেই — "bKash"-এর বাংলা নাম "bKash"-ই, আর সেটা দুইবার
 *     টাইপ করানোর কোনো কারণ নেই।
 *
 * ── `->change()` নিয়ে সাবধানতা ────────────────────────────────────────
 * `->change()` ঘরটা **সম্পূর্ণ নতুন করে লেখে** — যা আবার বলা হয়নি তা
 * মুছে যায়। আজ সকালেই ওই ফাঁদে পড়ে `users.ui`-এর NOT NULL আর ডিফল্ট
 * নীরবে হারিয়েছিল, আর তার দাম ছিল একটা ফেল ও ২২৭টা রোলব্যাক ত্রুটি।
 *
 * তাই নিচে প্রতিটা ঘরের পুরো সংজ্ঞা লেখা: ধরন, দৈর্ঘ্য, তারপর
 * `nullable()`।
 */
return new class extends Migration
{
    /**
     * ঘরগুলো — টেবিল => দৈর্ঘ্য।
     *
     * দৈর্ঘ্যগুলো আজকের সত্যিকারের মান থেকে নেওয়া, অনুমান নয়: চারটাই
     * `varchar(120)`। ছোট করে দিলে বসে থাকা নাম কেটে যেত।
     *
     * @var array<string, int>
     */
    private const COLUMNS = [
        'mdm_payment_methods' => 120,
        'mdm_brands' => 120,
        'mdm_product_categories' => 120,
        'acc_cost_centers' => 120,
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $length) {
            /*
             * টেবিলটা আছে কি না দেখে নেওয়া।
             *
             * `acc_cost_centers` আসে Accounts মডিউলের মাইগ্রেশন থেকে,
             * আর মডিউলের মাইগ্রেশনগুলো আলাদা ফোল্ডারে। ক্রম নিয়ে
             * অনুমান না করে জিজ্ঞেস করাই নিরাপদ।
             */
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($length) {
                $blueprint->string('name_bn', $length)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $length) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            /*
             * ফিরে যাওয়ার আগে খালি ঘরগুলো ভরে নেওয়া।
             *
             * নাহলে NOT NULL ফেরাতে গিয়ে MySQL নাল সারিগুলোয় আটকাত,
             * আর রোলব্যাকটা অর্ধেক পথে থেমে যেত — ঠিক আজ সকালের
             * ২২৭টা ত্রুটির মতো।
             */
            DB::table($table)->whereNull('name_bn')->update([
                'name_bn' => DB::raw('name_en'),
            ]);

            Schema::table($table, function (Blueprint $blueprint) use ($length) {
                $blueprint->string('name_bn', $length)->nullable(false)->change();
            });
        }
    }
};
