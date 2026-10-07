<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalDelegation;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * তারিখের ছাঁকনি MySQL-এর কাছ থেকে সূচকটা লুকিয়ে ফেলত।
 *
 * ── ⛔ কী ছিল, নিরীক্ষা §৫ ────────────────────────────────────────────
 * `whereDate('trx_date', '<=', $d)` SQL-এ হয় `DATE(trx_date) <= ?`।
 * ⚠️ কলামটা একটা ফাংশনের ভিতরে ঢুকে গেলে MySQL আর তার সূচক ব্যবহার
 * করতে পারে না — তাকে **প্রতিটা সারিতে** ফাংশনটা চালিয়ে দেখতে হয়।
 *
 * ⓘ ছোট টেবিলে কেউ টের পায় না। ⛔ খতিয়ান বা মজুদের চলাচলের টেবিলে,
 * যেখানে সারি লাখে গোনা হয়, ওটাই একটা পাতাকে সেকেন্ডের বদলে মিনিটে
 * নিয়ে যায় — আর কিছুই ভাঙে না, তাই কেউ রিপোর্টও করে না।
 *
 * ── ⭐ কেন সরানোটা নিরাপদ, আর সেটা মেপে জানা ──────────────────────────
 * ⓘ কলামটা সত্যিই `date` হলে `DATE(col)` কিছুই করে না, তাই সাধারণ
 * `where()` **হুবহু একই অর্থ** বহন করে আর সূচকটাও খাটে।
 *
 * ⛔ কিন্তু কলামটা `datetime` হলে অর্থ বদলে যেত: `<= '2026-09-28'`
 * মানে হত `<= 2026-09-28 00:00:00`, অর্থাৎ ঐ দিনের সব সারি বাদ।
 * ⚠️ তাই বদলানোর **আগে** পুরো স্কিমা মেপে দেখা হয়েছে — এই নামের
 * ৫২টা কলামের একটাও `datetime` নয়, সবগুলোই `date`।
 *
 * ── ⓘ এই ফাইলটা কী মাপে ──────────────────────────────────────────────
 * ⭐ সীমানার আচরণ: ঠিক শুরুর দিন আর ঠিক শেষ দিনের সারি **ভিতরে** থাকে।
 * ⓘ ওখানেই ভুলটা লুকাত — একটা ভুল সারাই দিনের সীমানায় সারি হারাত,
 * আর মাঝখানের সব তারিখে সবুজ দেখাত।
 *
 * ⛔ মাপে না: পাতাটা দ্রুত হলো কি না। ⓘ গতি একটা কোয়েরি-পরিকল্পনার
 * প্রশ্ন, আর সেটা এই সুইটের কাজ নয়; এখানকার দাবি কেবল **উত্তর
 * বদলায়নি** — আর গতির সারাই যখন উত্তর বদলায়, তখনই সেটা বিপজ্জনক।
 */
final class TheDateFilterHidTheIndexFromMysqlTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ সীমানার দিনগুলো ভিতরে থাকে ─────────────────────────────────

    public function test_the_financial_year_holds_its_own_first_and_last_day(): void
    {
        $year = FinancialYear::query()->orderBy('starts_on')->firstOrFail();

        /*
         * ⛔ পদ্ধতিটা **ডাকা হয়**, কোয়েরিটা এখানে আবার লেখা হয় না।
         *
         * ⚠️ প্রথম লেখায় ঠিক তাই করেছিলাম — `where(...)` নিজে লিখে
         * দাবি করেছিলাম। ⛔ ফলে [[FinancialYear::forDate()]]-এ `>=` কে `>` করে
         * দিলেও দাবিটা সবুজ থাকত — একটা মিউটেন্ট **বেঁচে গিয়ে**
         * সেটা ধরিয়ে দিয়েছে।
         *
         * ⓘ দাবিটা তখন কোডটাকে নয়, **আমার নিজের টাইপিং** পরীক্ষা করছিল।
         */
        foreach ([$year->starts_on, $year->ends_on] as $day) {
            $found = FinancialYear::forDate($day->toDateString());

            $this->assertNotNull($found,
                "বছরটা {$day->toDateString()} দিনটাকে নিজের ভিতরে পাচ্ছে না।");

            $this->assertSame((int) $year->id, (int) $found->id);
        }

        // ⓘ আর শুরুর আগের দিনটা এই বছরের নয়
        $before = FinancialYear::forDate($year->starts_on->copy()->subDay()->toDateString());

        $this->assertNotSame((int) $year->id, (int) ($before->id ?? 0),
            'বছর শুরুর আগের দিনটাও এই বছরে পড়ছে।');
    }

    public function test_a_delegation_covers_its_first_and_last_day(): void
    {
        /*
         * ⓘ সইয়ের দায়িত্ব হস্তান্তর — এখানে সীমানার ভুল মানে ছুটির
         * প্রথম বা শেষ দিনে কাগজ আটকে থাকা, আর কেউ বুঝতে পারে না কেন।
         */
        $from = Carbon::parse('2026-10-01');
        $to = Carbon::parse('2026-10-10');

        $users = User::query()->take(2)->get();
        $this->assertCount(2, $users, 'ডেমোতে দুইজন ব্যবহারকারীও নেই।');

        ApprovalDelegation::query()->create([
            'company_id' => $this->company->id,
            'from_user_id' => $users[0]->id,
            'to_user_id' => $users[1]->id,
            'starts_on' => $from->toDateString(),
            'ends_on' => $to->toDateString(),
        ]);

        foreach ([$from, $to] as $day) {
            $this->assertTrue($this->delegationActiveOn($day),
                "ছুটির {$day->toDateString()} দিনটায় হস্তান্তরটা চালু নেই।");
        }

        foreach ([$from->copy()->subDay(), $to->copy()->addDay()] as $day) {
            $this->assertFalse($this->delegationActiveOn($day),
                "পরিসরের বাইরের {$day->toDateString()} দিনেও হস্তান্তরটা চালু দেখাচ্ছে।");
        }
    }

    // ── ⓘ আর ফাংশনটা সত্যিই আর নেই ───────────────────────────────────

    public function test_no_column_is_wrapped_in_a_function_in_the_files_that_were_fixed(): void
    {
        /*
         * ⚠️ এটা একটা সরু পাহারা, আর সরু হওয়াটাই ইচ্ছাকৃত: গোটা রিপোর্টে
         * এখনো ১০০-র বেশি `whereDate` আছে, বারোটা মডিউলে ছড়ানো, আর
         * সেগুলো যাঁদের মডিউল তাঁদের কাজ (নিরীক্ষা §৫ ভাগ করা)।
         *
         * ⛔ পুরো রিপো ধরে একটা পাহারা আজ বসালে সেটা প্রথম দিনেই লাল
         * হয়ে আসত, আর একটা লাল-হয়ে-জন্মানো পাহারা কেউ পড়ে না — একদিন
         * কেউ ওটাকে চুপ করিয়ে দেয়। ⓘ ওটা সবার শেষে, সমন্বয়কের খাতায়।
         *
         * ⭐ এই দাবিটা কেবল আমার সারানো আটটা ফাইল ধরে — ওখানে ফিরে
         * আসা ঠেকাতে।
         */
        $files = [
            'app/Core/Concerns/FiltersByDate.php',
            'app/Core/Services/LedgerBalances.php',
            'app/Core/Services/NoticeScheduler.php',
            'app/Core/Services/StatusNotices.php',
            'app/Models/ApprovalDelegation.php',
            'app/Models/FinancialYear.php',
            'app/Modules/Inventory/Services/StockFacts.php',
            'app/Modules/Inventory/Services/StockTransferService.php',
        ];

        $offenders = [];

        foreach ($files as $file) {
            $path = base_path($file);

            $this->assertFileExists($path, "ফাইলটাই নেই — দাবিটা তাহলে কিছুই দেখছে না: {$file}");

            foreach (['whereDate(', 'orWhereDate(', 'whereMonth(', 'whereYear('] as $call) {
                if (str_contains(file_get_contents($path), $call)) {
                    $offenders[] = $file.' — '.$call;
                }
            }
        }

        $this->assertSame([], $offenders, implode(PHP_EOL, [
            'এই ফাইলগুলোয় তারিখের কলাম আবার একটা ফাংশনের ভিতরে ঢুকেছে:',
            '',
            '⚠️ `DATE(col) <= ?` লিখলে MySQL সূচকটা ব্যবহার করতে পারে না আর',
            'প্রতিটা সারিতে ফাংশনটা চালায়। কলামগুলো সত্যিকারের `date`, তাই',
            'সাধারণ `where()` হুবহু একই অর্থ বহন করে।',
            '',
            ...$offenders,
        ]));
    }

    private function delegationActiveOn(Carbon $day): bool
    {
        /* ⭐ মডেলের নিজের স্কোপ — নিজে কোয়েরি লিখলে দাবিটা কোডটাকে ছুঁতই না */
        return ApprovalDelegation::query()->active($day->toDateString())->exists();
    }
}
