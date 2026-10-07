<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * লাইভের MySQL আমাদেরটার চেয়ে কড়া — আর সেই ফাঁকেই তিনটা রিপোর্ট মরেছে।
 *
 * ── কী ঘটেছে, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────
 * গ্রাহকের তিনটা রিপোর্ট লাইভে ৫০০ দিল, দুই কোম্পানিতেই:
 *
 *     SQLSTATE[42000]: 1055 'customers.code' isn't in GROUP BY
 *
 * ⚠️ লোকাল MySQL-এ প্রশ্নটা দিব্যি চলত। পার্থক্যটা `sql_mode`: লাইভে
 * `ONLY_FULL_GROUP_BY` চালু, আমাদের মেশিনে নয়। ⛔ অর্থাৎ পরীক্ষাগুলো
 * সবুজ ছিল কারণ **প্রশ্নটা সঠিক ছিল না, পরীক্ষাটা অন্ধ ছিল**।
 *
 * ── এই ফাইলটা যা করে ────────────────────────────────────────────────
 * ⭐ সংযোগটায় লাইভের মোডটা বসিয়ে নেয়, তারপর যেসব পর্দা `GROUP BY` দিয়ে
 * প্রশ্ন করে সেগুলো চালায়। ⓘ পুরো পরীক্ষা-স্যুট কড়া মোডে চালানোই আসল
 * সমাধান, কিন্তু সেটা সবার চলতি কাজ থামিয়ে দিতে পারে — তাই আপাতত এই
 * পর্দাগুলো, আর সিদ্ধান্তটা সমন্বয়কারীর হাতে।
 *
 * ⚠️ নতুন কোনো `GROUP BY` লিখলে তার পর্দাটা এখানে যোগ করবেন। না করলে
 * ভুলটা আবার লোকালে নীরব থাকবে, আর লাইভে ৫০০ হয়ে ফিরবে।
 */
final class LiveMysqlWasStricterThanOursTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> গ্রুপ-বাই দিয়ে লেখা রিপোর্ট */
    private const REPORTS = [
        'customer.due_list',
        'customer.ageing',
        'customer.collection',
        'customer.no_limit',
    ];

    /**
     * গ্রুপ-বাই দিয়ে লেখা পর্দা — রুটের নাম।
     *
     * ⓘ অর্থের নতুন পর্দাগুলো (ব্যাংক চার্জ, পরিবহন খতিয়ান, খাত বিশ্লেষণ)
     * পরের কমিটে রুট পাবে, আর তখন সেগুলোর নামও এখানে বসবে।
     */
    private const SCREENS = [
        'purchase.payment_schedule.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        /*
         * ⓘ লাইভের মোডটা এই সংযোগে — আর পুরনো মোডগুলো রেখেই, কারণ
         * পার্থক্যটা কেবল এই একটা পতাকার।
         */
        DB::statement("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ONLY_FULL_GROUP_BY')");
    }

    public function test_the_mode_is_really_on(): void
    {
        $this->assertStringContainsString('ONLY_FULL_GROUP_BY', (string) DB::selectOne('SELECT @@sql_mode as m')->m,
            'কড়া মোডটাই বসেনি — তাহলে নিচের পরীক্ষাগুলো কিছুই প্রমাণ করে না।');

        /*
         * ⚠️ পাহারাটা অন্ধ কি না, সেটাও দেখা: এই প্রশ্নটা কড়া মোডে
         * থামতেই হবে। ⓘ না থামলে বুঝতে হবে মোডটা কাজ করছে না, আর তখন
         * বাকি সব সবুজ হত অকারণে।
         */
        $this->expectException(QueryException::class);
        DB::select('select id, code from customers group by company_id');
    }

    public function test_every_grouped_report_runs_where_mysql_is_strict(): void
    {
        foreach (self::REPORTS as $report) {
            $result = app(ReportEngine::class)->run($report, [
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->toDateString(),
            ]);

            $this->assertIsArray($result->rows, $report.': চলেনি।');
        }
    }

    public function test_every_grouped_screen_opens_where_mysql_is_strict(): void
    {
        foreach (self::SCREENS as $screen) {
            $response = $this->get(route($screen));

            $this->assertSame(200, $response->status(), $screen.': পর্দাটা খুলল না।');
        }

    }
}
