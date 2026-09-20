<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * কোনো সূচকের নাম যেন সীমানার গায়ে দাঁড়িয়ে না থাকে।
 *
 * ── ⛔ কেন এটা পাহারার মতো জিনিস ─────────────────────────────────────
 * MySQL-এ শনাক্তকারীর সীমা ৬৪ অক্ষর, আর Laravel সূচকের নাম **নিজে বানায়**
 * টেবিল ও কলামের নাম জুড়ে। ⚠️ সীমা ছাড়ালে `migrate:fresh` ভাঙে — আর
 * ভাঙে **সবার মেশিনে একসাথে**, কারণ সবাই একই মাইগ্রেশন চালায়।
 *
 * ⓘ ১৯ সেপ্টেম্বর ২০২৬-এ ঠিক এটাই ঘটেছিল: একটা ৬৫ অক্ষরের নাম চারটা
 * সেশনের টেস্ট ডেটাবেস একসাথে অচল করে দেয়, আর ভুলবার্তাটা দেখতে
 * মাইগ্রেশনের ভুলের মতো লাগত না।
 *
 * ── ⚠️ কেন ৬৪ নয়, ৬০ ─────────────────────────────────────────────────
 * ৬৪-এ দাঁড়ানো নাম আজ চলে, কিন্তু ঐ টেবিলে **একটা কলাম যোগ করলেই** কাল
 * ভাঙবে — আর তখন যিনি কলামটা যোগ করেছেন তিনি ভাববেন ভুলটা তাঁর নতুন
 * কলামে। ⓘ চার অক্ষরের ফাঁকটা সেই ফাঁদটাই সরায়।
 *
 * ⭐ সারাই সোজা, আর প্রতিটা মাইগ্রেশনে একই: দ্বিতীয় প্যারামিটারে নিজের
 * একটা ছোট নাম দিন — `$table->index([...], 'acc_stmt_line_date')`।
 */
final class NoIndexNameStandsAtTheEdgeTest extends TestCase
{
    /**
     * ⓘ MySQL-এর নিজের সীমা ৬৪; আমরা থামি ৬০-এ, বেড়ে ওঠার জায়গা রেখে।
     */
    private const COMFORTABLE = 60;

    /**
     * ⛔ যেগুলো এখনই সীমানার গায়ে — কারণসহ, আর তালিকাটা **বড় হবে না**।
     *
     * ⚠️ এখানে নাম বসানো মানে "জানা আছে, ভোলা হয়নি"। ⓘ নতুন সূচক এই
     * তালিকায় যোগ করা মানে পাহারাটাকে ফাঁকি দেওয়া — নাম ছোট করাই
     * একমাত্র সঠিক উত্তর।
     *
     * @var array<string, string>
     */
    private const AT_THE_EDGE = [
        'mdm_exchange_rates_company_id_currency_id_effective_from_unique' =>
            '৬৩ — MasterData-র, আর নাম বদলাতে চলতি ডেটাবেসেও rename লাগে (২১ সেপ্টেম্বর ২০২৬)',
        'mdm_exchange_rates_company_id_currency_id_effective_from_index' => '৬২ — একই টেবিল, একই কারণ',
        'customer_conduct_notes_company_id_customer_id_is_active_index' => '৬১ — Customer-এর',
        'inv_stock_movements_company_id_product_id_warehouse_id_index' => '৬০ — Inventory-র',
        'hr_leave_applications_company_id_employee_id_from_date_index' => '৬০ — Hr-এর',
        'fin_hand_loan_movements_company_id_account_id_moved_on_index' => '৬০ — Finance-এর',
    ];

    /**
     * ⭐ ডেটাবেস থেকেই পড়া হয়, মাইগ্রেশনের লেখা থেকে নয়।
     *
     * ⚠️ লেখা পড়লে কেবল হাতে-দেওয়া নামগুলো দেখা যেত; আসল বিপদ তো
     * **জেনারেট করা** নামগুলোতে, যেগুলো কোথাও লেখাই নেই।
     */
    public function test_no_generated_index_name_is_near_the_limit(): void
    {
        $database = DB::connection()->getDatabaseName();

        $names = DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->distinct()
            ->pluck('INDEX_NAME')
            ->merge(DB::table('information_schema.table_constraints')
                ->where('table_schema', $database)
                ->pluck('CONSTRAINT_NAME'))
            ->unique()
            ->all();

        /*
         * ⚠️ দাবিটা আগে — নাম না পেলে নিচের সব কিছু খালি তালিকায় পাশ
         * করত, আর পরীক্ষাটা সবুজ দেখিয়ে কিছুই মাপত না।
         */
        $this->assertGreaterThan(200, count($names),
            'সূচকের নামই পাওয়া যায়নি — পরীক্ষাটা কিছু মাপছে না।');

        $tooLong = [];

        foreach ($names as $name) {
            if (strlen((string) $name) < self::COMFORTABLE) {
                continue;
            }

            if (array_key_exists((string) $name, self::AT_THE_EDGE)) {
                continue;
            }

            $tooLong[] = strlen((string) $name).' — '.$name;
        }

        sort($tooLong);

        $this->assertSame([], $tooLong, implode("\n", [
            'এই সূচকের নামগুলো ৬৪ অক্ষরের সীমানার খুব কাছে।',
            '',
            '⚠️ সীমা ছাড়ালে migrate:fresh ভাঙে, আর ভাঙে সবার মেশিনে একসাথে।',
            'মাইগ্রেশনে নিজের একটা ছোট নাম দিন:',
            "    \$table->index([...], 'acc_stmt_line_date');",
            '',
            ...$tooLong,
        ]));
    }

    /**
     * ছাড়ের তালিকায় মৃত নাম জমে থাকে না।
     *
     * ⓘ সূচকটা ছোট করা হলে সারিটা এখানে পড়ে থাকত, আর পরের জন ভাবতেন
     * সমস্যাটা এখনো আছে। ⚠️ বাসি ছাড় কেবল আবর্জনা নয় — ওটা ভুল ইতিহাস।
     */
    public function test_the_edge_list_names_only_indexes_that_exist(): void
    {
        $database = DB::connection()->getDatabaseName();

        $live = DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->distinct()
            ->pluck('INDEX_NAME')
            ->all();

        $stale = array_values(array_diff(array_keys(self::AT_THE_EDGE), $live));

        $this->assertSame([], $stale, implode("\n", [
            'এই নামগুলো আর নেই — ছাড়ের সারিগুলো মুছে ফেলুন:',
            ...$stale,
        ]));
    }
}
