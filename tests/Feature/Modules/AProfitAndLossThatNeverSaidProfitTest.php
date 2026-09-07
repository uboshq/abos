<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\RealAccounts;
use Tests\TestCase;

/**
 * লাভ-ক্ষতির হিসাব কোথাও "লাভ" শব্দটাই বলত না।
 *
 * ── ⛔ কী পাওয়া গেছে লাইভে, ৭ সেপ্টেম্বর ২০২৬ ────────────────────────
 * একটা পুরো ব্যবসার চক্র হাতে চালানোর পর লাভ-ক্ষতির পর্দা খোলা হলো। সে
 * খাত ধরে ধরে সব দেখাল, আর নিচে লিখল:
 *
 *     সর্বমোট    ৫৮,০০০.০০    ৩১,০০০.০০    ২৭,০০০.০০
 *
 * ⓘ ব্যবসাটা ২৭,০০০ টাকা **হারিয়েছে**। ⛔ পর্দার কোথাও "লাভ" বা "ক্ষতি"
 * শব্দ দুটোর একটাও ছিল না — কেবল একটা ধনাত্মক সংখ্যা, যেটা দেখে বরং
 * উল্টোটাই মনে হয়।
 *
 * ── ⚠️ কেন এটা "সাজানোর" ব্যাপার নয় ─────────────────────────────────
 * একজন ব্যবসায়ীর কাছে গোটা হিসাবব্যবস্থার একটাই প্রশ্ন: **লাভ হলো, না
 * ক্ষতি?** ⓘ বাকি সব সারি ওই এক লাইনের ব্যাখ্যা।
 *
 * ⛔ যে লাভ-ক্ষতি হিসাব ওই লাইনটা দেয় না, সে হিসাব নয় — সে একটা তালিকা,
 * আর পাঠককে নিজের মাথায় যোগ-বিয়োগ করতে হয়। ⚠️ মাথায় করা যোগ-বিয়োগ ভুল
 * হয়, আর ভুলটা কেউ ধরতে পারে না, কারণ পর্দায় কোনো দাবিই লেখা নেই।
 *
 * ⓘ স্থিতিপত্র এটা আগে থেকেই করত (*"খাতা মেলে — সম্পদ = দায় + মূলধন"*)।
 * ⭐ আজকের চেনা ছাঁচ আবার: **নীতির অভাব নয়, পৌঁছানোর অভাব**।
 */
class AProfitAndLossThatNeverSaidProfitTest extends TestCase
{
    use RealAccounts;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
    }

    private static int $seq = 0;

    /**
     * খতিয়ানে সরাসরি দুইটা সারি — ভাউচারের পর্দা এড়িয়ে।
     *
     * ⓘ এই ফাইলের দাবিগুলো **লাভ-ক্ষতির অঙ্ক** নিয়ে, ভাউচারের ফর্ম নিয়ে
     * নয়। ⚠️ ফর্ম দিয়ে বসালে ওই পর্দার যাচাই বদলালে এই টেস্টগুলোও লাল
     * হত — আর তখন লাল রঙটা ভুল জায়গার কথা বলত।
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function postJournal(string $narration, array $lines): void
    {
        app(\App\Core\Engines\Posting\PostingEngine::class)->post(
            sourceType: 'test.journal',
            sourceId: ++self::$seq,
            trxDate: now()->toDateString(),
            lines: array_map(fn (array $l) => $l + ['narration' => $narration], $lines),
            documentNo: 'TJ-'.self::$seq,
        );
    }

    /** @return array{label: string, value: string, good: bool}|null */
    private function summary(): ?array
    {
        $report = app(ReportEngine::class)->get('accounts.profit_loss');

        $this->assertNotNull(
            $report->summary,
            "লাভ-ক্ষতির রিপোর্ট কোনো সারসংক্ষেপ ঘোষণা করে না।\n"
            .'অর্থাৎ পর্দায় "লাভ" বা "ক্ষতি" শব্দটা আর দেখা যাবে না।',
        );

        $result = app(ReportEngine::class)->run('accounts.profit_loss', [
            'from' => now()->startOfYear()->toDateString(),
            'to' => now()->endOfYear()->toDateString(),
        ]);

        return ($report->summary)($result->totals);
    }

    /**
     * ⛔ খরচ আয়ের চেয়ে বেশি — পর্দা **ক্ষতি** বলবে।
     *
     * ⓘ ঠিক লাইভের ছবিটাই: বিক্রি ২৭,০০০, খরচ ৪৯,৫০০ (মালের ব্যয় +
     * বেতন + ভাড়া)। ⚠️ ফল ২২,৫০০ ক্ষতি, অথচ পুরনো পর্দায় সংখ্যাটা
     * ধনাত্মক দেখাত আর কোনো শব্দ থাকত না।
     */
    public function test_a_business_that_lost_money_is_told_it_lost_money(): void
    {
        $this->postJournal('বিক্রি', [
            ['account_id' => $this->cashAccountId(), 'debit' => '27000', 'credit' => '0'],
            ['account_id' => $this->salesAccountId(), 'debit' => '0', 'credit' => '27000'],
        ]);

        $this->postJournal('ভাড়া ও বেতন', [
            ['account_id' => $this->accountByCode('5202'), 'debit' => '49500', 'credit' => '0'],
            ['account_id' => $this->cashAccountId(), 'debit' => '0', 'credit' => '49500'],
        ]);

        $summary = $this->summary();

        $this->assertFalse(
            $summary['good'],
            "ব্যবসাটা ২২,৫০০ টাকা হারিয়েছে, অথচ পর্দা সেটাকে ভালো খবর হিসেবে দেখাচ্ছে।\n"
            .'⚠️ সবুজ ব্যানারে ক্ষতি দেখানো ভুল বলার চেয়েও খারাপ।',
        );

        $this->assertSame(
            __('accounts::message.net_loss'),
            $summary['label'],
            'ক্ষতির সময় পর্দায় "নিট ক্ষতি" লেখা থাকতে হবে — সংখ্যাটা নিজে দিক বলে না।',
        );

        $this->assertEqualsWithDelta(
            22500.0,
            (float) $summary['value'],
            0.01,
            "ক্ষতির অঙ্কটা ভুল।\n"
            .'আয় ২৭,০০০ − খরচ ৪৯,৫০০ = ২২,৫০০ ক্ষতি; ধনাত্মক সংখ্যায় দেখানো হয়, শব্দটাই দিক বলে।',
        );
    }

    /** ⭐ আর লাভ হলে লাভই বলে — উল্টোদিকটাও মাপা। */
    public function test_a_business_that_made_money_is_told_it_made_money(): void
    {
        $this->postJournal('বিক্রি', [
            ['account_id' => $this->cashAccountId(), 'debit' => '80000', 'credit' => '0'],
            ['account_id' => $this->salesAccountId(), 'debit' => '0', 'credit' => '80000'],
        ]);

        $this->postJournal('ভাড়া', [
            ['account_id' => $this->accountByCode('5202'), 'debit' => '30000', 'credit' => '0'],
            ['account_id' => $this->cashAccountId(), 'debit' => '0', 'credit' => '30000'],
        ]);

        $summary = $this->summary();

        $this->assertTrue($summary['good'], 'লাভ হয়েছে, অথচ পর্দা ক্ষতি দেখাচ্ছে।');
        $this->assertSame(__('accounts::message.net_profit'), $summary['label']);
        $this->assertEqualsWithDelta(50000.0, (float) $summary['value'], 0.01,
            'লাভের অঙ্ক ভুল — ৮০,০০০ − ৩০,০০০ = ৫০,০০০।');
    }

    /**
     * ⚠️ বিক্রয় ফেরত **একবারই** বাদ যায়।
     *
     * ── কেন এটা আলাদা করে মাপা ──────────────────────────────────────
     * ⓘ বিক্রয় ফেরতের খাত (৪১১০) আয়ের ঘরে বসে, কিন্তু বাড়ে **ডেবিটে**।
     * ⭐ তাই `ক্রেডিট − ডেবিট` কষলে সে নিজে থেকেই আয় কমায়।
     *
     * ⛔ কেউ যদি "ফেরত তো আয় কমায়" ভেবে আলাদা করে আবার বাদ দেন, তখন
     * **দুইবার** বাদ যাবে — আর ভুলটা নীরব, কারণ সংখ্যাটা তবু বিশ্বাসযোগ্য
     * দেখাবে।
     */
    public function test_a_sales_return_is_subtracted_once_and_only_once(): void
    {
        $this->postJournal('বিক্রি', [
            ['account_id' => $this->cashAccountId(), 'debit' => '27000', 'credit' => '0'],
            ['account_id' => $this->salesAccountId(), 'debit' => '0', 'credit' => '27000'],
        ]);

        // ৪,৫০০ টাকার মাল ফেরত — আয় কমার কথা ঠিক ৪,৫০০
        $this->postJournal('বিক্রয় ফেরত', [
            ['account_id' => $this->accountByCode('4110'), 'debit' => '4500', 'credit' => '0'],
            ['account_id' => $this->cashAccountId(), 'debit' => '0', 'credit' => '4500'],
        ]);

        $summary = $this->summary();

        $this->assertTrue($summary['good']);
        $this->assertEqualsWithDelta(
            22500.0,
            (float) $summary['value'],
            0.01,
            "ফেরতটা একবারের বেশি বাদ গেছে।\n"
            .'২৭,০০০ − ৪,৫০০ = ২২,৫০০ থাকার কথা; ১৮,০০০ মানে দুইবার বাদ গেছে।',
        );
    }
}
