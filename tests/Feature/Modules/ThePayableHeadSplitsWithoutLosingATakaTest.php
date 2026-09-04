<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * প্রদেয় খাতটা ভাগ হয়, কিন্তু একটা টাকাও নড়ে না।
 *
 * ── কেন এই পাহারাটা সবচেয়ে জরুরি ────────────────────────────────────
 * `2110` এখন একটা **দল**, আর দলে দাখিলা বসে না — তাই পুরনো সারিগুলো
 * সরাতেই হয়। ⚠️ **অর্ধেক সরানো দাখিলা অর্ধেক বসা খোলার জেরের চেয়েও
 * খারাপ**: খতিয়ান মিলবে, স্থিতিপত্র মিলবে না, আর কেউ টের পাবে না।
 *
 * ── ⭐ কেন যোগফলটা কোম্পানিপ্রতি মেলানো হয় ──────────────────────────
 * চারটা কোম্পানির চারটা আলাদা চার্ট। মোট একসাথে মেলালে **একটার টাকা
 * আরেকটার সাথে কাটাকাটি হয়ে ভুলটা ঢেকে যেত** — একজনের ৫,০০০ বেড়ে
 * অন্যজনের ৫,০০০ কমলে যোগফল অবিকল থাকত, অথচ দুইটা খাতাই ভুল।
 *
 * ⓘ এখানে দুইটা কোম্পানি ইচ্ছে করে **আলাদা অঙ্কে** — সমান হলে
 * কাটাকাটির ভুলটা ধরা পড়ত না।
 */
class ThePayableHeadSplitsWithoutLosingATakaTest extends TestCase
{
    use RefreshDatabase;

    private Company $ours;

    private Company $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ours = $this->companyWithChart('P1', 'Depot One');
        $this->theirs = $this->companyWithChart('P2', 'Depot Two');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function companyWithChart(string $code, string $name): Company
    {
        $company = Company::create(['code' => $code, 'name_en' => $name]);
        CompanyContext::set($company->id);
        app(StandardChart::class)->install();

        /*
         * প্রতিটা দাখিলা একটা অর্থবছরের — কলামটা নালযোগ্য নয়।
         *
         * ⓘ সারিগুলো এখানে হাতে বসানো হয় (`PostingEngine` ছাড়া), কারণ
         * পরীক্ষার বিষয় খাতের ভাগ, পোস্টিং নয়। তবু ডাটাবেসের শর্তগুলো
         * একই — আর সেটাই ঠিক: টেবিল যা দাবি করে, টেস্টও তা-ই দেয়।
         */
        FinancialYear::create([
            'name' => '2026-2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'is_current' => true,
        ]);

        return $company;
    }

    /** কোড ধরে খাতটা — কোম্পানি ধরে, কারণ প্রতিটার নিজের চার্ট। */
    private function account(Company $company, string $code): object
    {
        return DB::table('accounts')
            ->where('company_id', $company->id)
            ->where('code', $code)
            ->first(['id', 'is_group', 'parent_id']);
    }

    /**
     * মাইগ্রেশনের আগের অবস্থাটা হাতে বানানো।
     *
     * ⓘ `RefreshDatabase` মাইগ্রেশনগুলো আগেই চালিয়ে রেখেছে, আর তখন কোনো
     * কোম্পানিই ছিল না — তাই ভাগটা এই কোম্পানিগুলোতে ঘটেনি। এখানে ছকটা
     * পুরনো আকারে ফিরিয়ে নিয়ে **তারপর** মাইগ্রেশনটা চালানো হয়, যাতে
     * পরীক্ষাটা সত্যিই ওই কোডটাই মাপে।
     */
    private function rollBackToOneHead(Company $company): void
    {
        $group = $this->account($company, '2110');

        DB::table('accounts')
            ->where('company_id', $company->id)
            ->whereIn('code', ['2111', '2116', '2117', '2118'])
            ->delete();

        DB::table('accounts')->where('id', $group->id)->update(['is_group' => false]);
    }

    private function owe(Company $company, string $code, string $amount): void
    {
        DB::table('ledger_entries')->insert([
            'company_id' => $company->id,
            'financial_year_id' => DB::table('financial_years')
                ->where('company_id', $company->id)->value('id'),
            'account_id' => $this->account($company, $code)->id,
            'trx_date' => now()->toDateString(),

            /*
             * প্রতিটা সারি কোনো না কোনো কাগজের — কলাম দুইটা নালযোগ্য নয়।
             *
             * ⓘ "নিয়ম ১: প্রতিটা সংখ্যা তার উৎসে যায়" এখানে **স্কিমাতেই
             * বাঁধা** — উৎস ছাড়া একটা দাখিলা টেবিলে বসতেই পারে না।
             * এখানে একটা ক্রয় বিলের ভান করা হয়, কারণ প্রদেয়ে ওভাবেই
             * টাকা আসে।
             */
            'source_type' => 'purchase_bill',
            'source_id' => 1,
            'debit' => '0',
            'credit' => $amount,
            'narration' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payableTotal(Company $company): string
    {
        $group = $this->account($company, '2110');

        $ids = DB::table('accounts')
            ->where('company_id', $company->id)
            ->where(fn ($q) => $q->where('id', $group->id)->orWhere('parent_id', $group->id))
            ->pluck('id');

        return (string) DB::table('ledger_entries')
            ->where('company_id', $company->id)
            ->whereIn('account_id', $ids)
            ->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS net')
            ->value('net');
    }

    private function runTheSplit(): void
    {
        $migration = require base_path(
            'app/Modules/Accounts/Database/Migrations/'
            .'2026_10_24_100000_one_payable_head_held_three_different_debts.php'
        );

        $migration->up();
    }

    public function test_a_fresh_company_is_born_with_the_right_shape(): void
    {
        $group = $this->account($this->ours, '2110');

        $this->assertSame(1, (int) $group->is_group, '২১১০ দল নয় — তাহলে ভেতরের ঘরগুলো ঝুলে থাকত');

        foreach (['2111', '2116', '2117', '2118'] as $code) {
            $child = $this->account($this->ours, $code);

            $this->assertNotNull($child, "নতুন ইনস্টলে {$code} ঘরটাই নেই");
            $this->assertSame((int) $group->id, (int) $child->parent_id, "{$code} ২১১০-এর নিচে নেই");
            $this->assertSame(0, (int) $child->is_group, "{$code} দল হয়ে গেছে — ওখানে দাখিলা বসবে না");
        }
    }

    /**
     * ⭐ পোস্টিং কোডটা যে খাত খোঁজে, সেটা দল নয়।
     *
     * ⚠️ এগারোটা জায়গা `StandardChart::PAYABLE` ধরে খাত খোঁজে। কোডটা
     * ২১১০-এ থেকে গেলে ওই এগারোটা পথই **একটা গ্রুপ খাতে** পোস্ট করতে
     * যেত, আর প্রতিটা ক্রয় বিল ভাঙত।
     */
    public function test_the_code_that_posts_never_lands_on_a_group(): void
    {
        CompanyContext::set($this->ours->id);

        foreach ([StandardChart::PAYABLE, StandardChart::TRANSPORT_PAYABLE,
            StandardChart::LABOUR_PAYABLE, StandardChart::VENDOR_PAYABLE] as $code) {
            $found = StandardChart::find($code);

            $this->assertNotNull($found, "{$code} খুঁজে পাওয়া যায়নি");
            $this->assertFalse((bool) $found->is_group, "{$code} একটা দল — এখানে দাখিলা বসানো যায় না");
        }

        $this->assertSame('2111', StandardChart::PAYABLE, 'ডিফল্ট গন্তব্য বদলে গেছে');
        $this->assertSame('2110', StandardChart::PAYABLE_GROUP);
    }

    /** ⭐ আসল পাহারা: সরানোর আগের যোগফল = পরের যোগফল, কোম্পানিপ্রতি। */
    public function test_not_one_taka_moves_between_companies(): void
    {
        // ইচ্ছে করে আলাদা অঙ্ক — সমান হলে কাটাকাটির ভুল ধরা পড়ত না
        $this->rollBackToOneHead($this->ours);
        $this->rollBackToOneHead($this->theirs);

        $this->owe($this->ours, '2110', '125000.0000');
        $this->owe($this->ours, '2110', '8400.5000');
        $this->owe($this->theirs, '2110', '3300.0000');

        $before = [
            'ours' => $this->payableTotal($this->ours),
            'theirs' => $this->payableTotal($this->theirs),
        ];

        // ⚠️ শূন্যের উপর চালানো পরীক্ষা সবসময় সবুজ
        $this->assertSame('133400.5000', $before['ours']);
        $this->assertSame('3300.0000', $before['theirs']);

        $this->runTheSplit();

        $this->assertSame($before['ours'], $this->payableTotal($this->ours), 'প্রথম কোম্পানির প্রদেয় বদলে গেছে');
        $this->assertSame($before['theirs'], $this->payableTotal($this->theirs), 'দ্বিতীয় কোম্পানির প্রদেয় বদলে গেছে');
    }

    /** আর দলটায় একটা সারিও পিছিয়ে থাকে না। */
    public function test_nothing_is_left_behind_on_the_group(): void
    {
        $this->rollBackToOneHead($this->ours);
        $this->owe($this->ours, '2110', '9000.0000');

        $this->runTheSplit();

        $stranded = DB::table('ledger_entries')
            ->where('company_id', $this->ours->id)
            ->where('account_id', $this->account($this->ours, '2110')->id)
            ->count();

        $this->assertSame(0, $stranded, 'দলে দাখিলা পড়ে আছে — পরের পোস্টিং ভাঙবে');

        $this->assertSame('9000.0000', (string) DB::table('ledger_entries')
            ->where('account_id', $this->account($this->ours, '2111')->id)
            ->sum('credit'), 'সারিটা ডিফল্ট ঘরে যায়নি');
    }

    /**
     * দুইবার চালালেও কিছু বদলায় না।
     *
     * ⓘ মাইগ্রেশন সাধারণত একবারই চলে, কিন্তু একটা ব্যর্থ deploy-এর পর
     * হাতে আবার চালানো হয়। দ্বিতীয়বারে ঘর দুইটা করে বসলে বা টাকা
     * দ্বিগুণ হলে সেটা ধরা পড়ত অনেক পরে।
     */
    public function test_running_it_twice_changes_nothing(): void
    {
        $this->rollBackToOneHead($this->ours);
        $this->owe($this->ours, '2110', '5000.0000');

        $this->runTheSplit();
        $once = $this->payableTotal($this->ours);

        $this->runTheSplit();

        $this->assertSame($once, $this->payableTotal($this->ours));
        $this->assertSame(1, DB::table('accounts')
            ->where('company_id', $this->ours->id)->where('code', '2111')->count());
    }

    /** নতুন ঘরগুলো দায়ের প্রকৃতির — নাহলে স্থিতিপত্রে উল্টো দিকে বসত। */
    public function test_the_new_heads_are_liabilities(): void
    {
        foreach (['2111', '2116', '2117', '2118'] as $code) {
            $row = DB::table('accounts')
                ->where('company_id', $this->ours->id)->where('code', $code)
                ->first(['type', 'nature']);

            $this->assertSame(Account::LIABILITY, $row->type, "{$code} দায় নয়");
            $this->assertSame(Account::defaultNatureFor(Account::LIABILITY), $row->nature);
        }
    }
}
