<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\LedgerBalances;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * অনেক খাতের জের একবারে তোলা হয়, প্রতি খাতে একবার নয়।
 *
 * ── কেন, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * ⛔ [[Account::balanceOn]] প্রতিটা পাতা-খাতে একটা করে
 * `SUM(debit), SUM(credit)` চালাত। ⓘ মেপে দেখা: ১৯০ খাতের ছকে **৮১টা
 * কোয়েরি** একটা পাতা আঁকতে, আর প্রতিটার কোনো নিচের তারিখ-সীমা নেই —
 * অর্থাৎ ঐ খাতের পুরো ইতিহাস প্রতিবার।
 *
 * ── ⭐ সবচেয়ে জরুরি দাবিটা গতির নয়, টাকার ───────────────────────────
 * ⚠️ চিহ্নটা প্রতিটা খাত নিজের **প্রকৃতি** ধরে ঠিক করে (আয়/দায় ঋণাত্মক,
 * সম্পদ/ব্যয় ধনাত্মক)। ⛔ একবারে তোলার নামে ঐ নিয়ম বদলে গেলে সংখ্যাটা
 * নীরবে উল্টে যেত, আর সেটা গতির সমস্যার চেয়ে অনেক খারাপ।
 *
 * ⭐ তাই নিচের প্রথম পরীক্ষাটা **উত্তর মেলায়**, আর দ্বিতীয়টা খরচ।
 */
final class TheChartCountedEveryAccountOneAtATimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⛔ আগে-তোলা উত্তর আর নিজে-গোনা উত্তর হুবহু এক।
     *
     * ⓘ প্রতিটা পোস্টেবল খাত ধরে মেলানো হয়, কেবল নমুনা নয় — চিহ্নের
     * ভুল সাধারণত এক ধরনের খাতেই দেখা দেয়, আর নমুনায় ঠিক ঐটাই বাদ
     * পড়তে পারত।
     */
    public function test_the_preloaded_answer_matches_the_one_counted_alone(): void
    {
        $accounts = Account::query()->where('is_group', false)->get();

        $this->assertGreaterThan(20, $accounts->count(), 'ছকে খাতের সংখ্যা সন্দেহজনকভাবে কম।');

        /* ⓘ আগে নিজে-গোনা উত্তরগুলো — স্মৃতি খালি রেখে। */
        $alone = [];

        foreach ($accounts as $account) {
            $alone[$account->id] = $account->balanceOn();
        }

        app(LedgerBalances::class)->preload($accounts->pluck('id')->map(fn ($i) => (int) $i)->all());

        $mismatch = [];

        foreach ($accounts as $account) {
            $now = $account->balanceOn();

            if ($now !== $alone[$account->id]) {
                $mismatch[] = $account->code.': একা '.$alone[$account->id].' · একসাথে '.$now;
            }
        }

        $this->assertSame([], $mismatch, implode("\n", array_merge(
            ['⛔ একবারে তোলার পর জেরের সংখ্যা বদলে গেছে:', ''],
            $mismatch,
            ['', '⚠️ প্রথম সন্দেহ চিহ্ন: প্রতিটা খাত নিজের প্রকৃতি ধরে চিহ্ন',
                'বসায়, আর সেই নিয়মটা `balanceOn()`-এই থাকার কথা।']
        )));
    }

    /**
     * ⛔ আর খরচটা সত্যিই কমে — একবারে তোলার পর আর কোনো কোয়েরি নয়।
     */
    public function test_many_balances_cost_one_query_not_one_each(): void
    {
        $accounts = Account::query()->where('is_group', false)->limit(25)->get();
        $ids = $accounts->pluck('id')->map(fn ($i) => (int) $i)->all();

        /*
         * ⭐ ধনাত্মক নিয়ন্ত্রণ: আগে-তোলা ছাড়া খরচটা সত্যিই বেশি।
         * ⚠️ এটা না থাকলে নিচের "শূন্য কোয়েরি" দাবিটা প্রমাণ করত না
         * যে কিছু বাঁচল — হয়তো কোয়েরি কোনোদিনই হত না।
         */
        $alone = 0;
        DB::listen(function () use (&$alone) {
            $alone++;
        });

        foreach ($accounts as $account) {
            $account->balanceOn();
        }

        $this->assertGreaterThanOrEqual(
            20,
            $alone,
            "একা গুনতে মাত্র {$alone}টা কোয়েরি গেল — তাহলে বাঁচানোর মতো কিছু ছিল না।"
        );

        app(LedgerBalances::class)->preload($ids);

        $after = 0;
        DB::listen(function () use (&$after) {
            $after++;
        });

        foreach ($accounts as $account) {
            $account->balanceOn();
        }

        $this->assertSame(0, $after, "আগে তোলার পরেও {$after}টা কোয়েরি গেছে।");
    }

    /**
     * ⓘ যে খাতে একটাও সারি নেই সেও "তোলা হয়েছে" গণ্য হয়।
     *
     * ⚠️ নাহলে খালি খাতগুলোর জন্য N+1 রয়েই যেত — আর নতুন ছকে খালি
     * খাতই বেশি।
     */
    public function test_an_account_with_no_entries_is_remembered_too(): void
    {
        /*
         * ⓘ সম্পর্ক ধরে নয়, খতিয়ান ধরে — `Account`-এ খতিয়ানের কোনো
         * সম্পর্ক ঘোষিত নেই, আর কেবল এই পরীক্ষার জন্য একটা যোগ করা
         * মানে মডেলে এমন কিছু বসানো যা কোড কোথাও ব্যবহার করে না।
         */
        $used = DB::table('ledger_entries')->distinct()->pluck('account_id')->all();

        $empty = Account::query()
            ->where('is_group', false)
            ->whereNotIn('id', $used ?: [0])
            ->first();

        if ($empty === null) {
            $this->markTestSkipped('ডেমোতে এন্ট্রিহীন খাত নেই — তুলনাটা অর্থহীন।');
        }

        app(LedgerBalances::class)->preload([(int) $empty->id]);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $empty->balanceOn();

        $this->assertSame(0, $queries, 'খালি খাতটা মনে রাখা হয়নি, তাই আবার জিজ্ঞেস করা হলো।');
    }
}
