<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Modules\Inventory\Http\Controllers\StockReportController;
use ReflectionClass;
use Tests\TestCase;

/**
 * কোন ব্যাচে কত আছে — প্রশ্নটা করার কোনো জায়গা ছিল না।
 *
 * ── ⛔ মালিকের নির্দেশ, ১৮ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"ইনভেন্টরিতে স্টক আলাদা ম্যানেজ হওয়ার কথা ছিল।"* ⓘ চারটা ভাগের
 * তালিকা করে দেখা গেল তিনটা আগে থেকেই ছিল —
 *
 *   · ফ্রি/উপহার  → `free_change`, আর পর্দায় বসানো হলো আজ
 *   · গুদামভিত্তিক → মজুদের তালিকায় ছাঁকনিটা আগে থেকেই ছিল
 *   · ড্যামেজ/ফেরত → "আটকানো" + কারণ-কোড, আর `inventory.hold`
 *                    রিপোর্ট কারণ ধরেই ভাগ করে দেখায়
 *
 * ⛔ **ব্যাচ-ভাগটাই ছিল একমাত্র সত্যিকারের ফাঁক।** ⓘ `inv_batches`
 * টেবিল ছিল, চলাচলে `batch_id` ছিল, [[BatchAllocator]] লট ধরে মালও
 * কাটত — কিন্তু *"এই মুহূর্তে কোন ব্যাচে কত আছে"* জিজ্ঞেস করার কোনো
 * পর্দা ছিল না। ⚠️ মেয়াদের রিপোর্ট কেবল **মেয়াদ থাকা** লট দেখাত।
 */
final class NobodyCouldAskWhichBatchHadWhatTest extends TestCase
{
    /**
     * ⭐ রিপোর্টটা ইঞ্জিনে নিবন্ধিত, আর তার কলামগুলো আসলেই আলাদা।
     */
    public function test_the_batch_report_is_registered_with_its_own_columns(): void
    {
        $report = app(ReportEngine::class)->get('inventory.stock_by_batch');

        $keys = array_column($report->columns, 'key');

        /*
         * ⛔ এই চারটাই রিপোর্টটার কারণ। ⓘ ব্যাচ ও গুদাম না থাকলে এটা
         * আরেকটা মজুদের তালিকা, আর ফ্রি না থাকলে মালিকের প্রশ্নের
         * উত্তরটাই বাদ পড়ে।
         */
        foreach (['batch_no', 'warehouse_name', 'on_hand', 'free_on_hand'] as $needed) {
            $this->assertContains($needed, $keys,
                'ব্যাচের রিপোর্টে "'.$needed.'" কলামটা নেই।');
        }
    }

    /**
     * ⭐ মেনুর প্রতিটা রিপোর্ট-সারির ঠিকানা সত্যিই খোলে।
     *
     * ── ⛔ কেন এই দাবিটা আলাদা করে দরকার ─────────────────────────────
     * [[StockReportController]] স্লাগগুলো একটা হাতে-লেখা তালিকায় রাখে,
     * আর মেনু আলাদা করে স্লাগ পাঠায়। ⚠️ দুইটা আলাদা জায়গা, আর মাঝখানে
     * কোনো জোড়া নেই — একটায় লিখে অন্যটায় ভুলে গেলে সারিটা **৪০৪** দেয়,
     * অথচ রিপোর্টটা দিব্যি লেখা ও নিবন্ধিত থাকে।
     *
     * ⓘ আজ ব্যাচের রিপোর্ট যোগ করার সময় ঠিক এই ভুলটা হয়েছিল: স্লাগ
     * লেখা হয়েছিল `stock_by_batch`, আর তালিকার বাকি সবগুলো হাইফেনে।
     *
     * ⚠️ দাবিটা **মেনু থেকে** শুরু হয়, তালিকা থেকে নয়: তালিকায় বাড়তি
     * একটা স্লাগ থাকলে কারও ক্ষতি নেই, কিন্তু মেনুতে এমন সারি থাকলে
     * মানুষ ক্লিক করে ৪০৪ পান।
     */
    public function test_every_report_row_in_the_menu_has_a_slug_that_resolves(): void
    {
        $module = require base_path('app/Modules/Inventory/module.php');

        $slugs = (new ReflectionClass(StockReportController::class))
            ->getConstant('SLUGS');

        $engine = app(ReportEngine::class);
        $checked = 0;

        foreach ($module['menu'] ?? [] as $group) {
            foreach ($group as $item) {
                if (! is_array($item) || ($item['route'] ?? '') !== 'inventory.report.show') {
                    continue;
                }

                $slug = $item['route_params']['slug'] ?? '';
                $checked++;

                $this->assertArrayHasKey($slug, $slugs,
                    'মেনুর "'.$slug.'" সারিটা কোনো স্লাগে পৌঁছায় না — ক্লিক করলে ৪০৪।');

                /*
                 * ⓘ আর স্লাগটা যে চাবিতে যায় সেটাও সত্যিই নিবন্ধিত কি না।
                 * ⛔ নাহলে ৪০৪-এর বদলে ৫০০ আসত — আরও খারাপ।
                 */
                $engine->get($slugs[$slug]);
            }
        }

        $this->assertGreaterThan(3, $checked,
            'মেনুতে রিপোর্টের সারিই পাওয়া যায়নি — পাহারাটা অন্ধ হয়ে গেছে।');
    }
}
