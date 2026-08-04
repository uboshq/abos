<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * অর্থবছর বন্ধ ও পরের বছর খোলা।
 *
 * এটা বছরে একবার চলে — আর সেটাই এর সবচেয়ে বড় ঝুঁকি। যে কাজ বছরে একবার
 * হয় তার ভুলগুলো কেউ চেনে না, ভুল ধরার লোকও থাকে না, আর ভুল হলে পুরো
 * খাতা এলোমেলো। তাই এখানকার পরীক্ষা অন্য জায়গার চেয়ে কড়া।
 *
 * সবচেয়ে জরুরি দুইটা: খাতা বন্ধের পরেও মেলে কি না, আর পক্ষভিত্তিক
 * বকেয়া প্রতি পক্ষে আলাদা করে টানা হয় কি না।
 */
class YearEndTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private FinancialYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->actingAs($this->user);

        $this->year = FinancialYear::query()->where('is_current', true)->firstOrFail();
    }

    private function service(): YearEndService
    {
        return app(YearEndService::class);
    }

    // ── পরের বছরের প্রস্তাব ────────────────────────────────────────────

    public function test_the_next_year_starts_the_day_after_this_one_ends(): void
    {
        $next = $this->service()->nextYearFor($this->year);

        $this->assertSame('2027-07-01', $next['starts_on']);
        $this->assertSame('2028-06-30', $next['ends_on']);
        $this->assertSame('2027-2028', $next['name']);
    }

    /**
     * একদিনের ফাঁকও থাকে না।
     *
     * থাকলে ওই তারিখের এন্ট্রি কোনো বছরেই পড়ত না, আর FinancialYear::forDate()
     * null ফেরত দিত — তখন ওই দিনের বিলটা লেখাই যেত না, কারণ কারণটা
     * পর্দায় বোঝার উপায় নেই।
     */
    public function test_there_is_no_gap_between_one_year_and_the_next(): void
    {
        $next = $this->service()->close($this->year);

        $this->assertTrue(
            $next->starts_on->isSameDay($this->year->fresh()->ends_on->copy()->addDay()),
            'দুই বছরের মাঝে একটা দিন কোথাও পড়ে না।',
        );
    }

    // ── আয়-ব্যয় বন্ধ হয় ───────────────────────────────────────────────

    /**
     * বছর বন্ধের পর আয় ও ব্যয়ের খাত শূন্য।
     *
     * না হলে পরের বছরের লাভ-লোকসানে আগের বছরের বিক্রি যোগ হয়ে যেত, আর
     * সেটা ধরা পড়ত কেবল যখন কেউ দুই বছরের সংখ্যা মিলিয়ে দেখত।
     */
    public function test_income_and_expense_accounts_are_zero_after_closing(): void
    {
        $this->trade(income: '50000.0000', expense: '30000.0000');

        $this->service()->close($this->year);

        foreach ([Account::INCOME, Account::EXPENSE] as $type) {
            $net = LedgerEntry::query()
                ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->where('accounts.type', $type)
                ->whereDate('ledger_entries.trx_date', '<=', $this->year->ends_on)
                ->selectRaw('COALESCE(SUM(ledger_entries.debit) - SUM(ledger_entries.credit), 0) as net')
                ->value('net');

            $this->assertSame(0, bccomp((string) $net, '0', 4), "{$type} খাত শূন্য হয়নি।");
        }
    }

    /**
     * লাভটা সঞ্চিত মুনাফায় যায়, হারিয়ে যায় না।
     */
    public function test_the_years_profit_lands_in_retained_earnings(): void
    {
        $this->trade(income: '50000.0000', expense: '30000.0000');

        $equity = StandardChart::find(StandardChart::RETAINED_EARNINGS);

        $before = $equity->balanceOn($this->year->ends_on->toDateString());

        $this->service()->close($this->year);

        $after = $equity->fresh()->balanceOn($this->year->ends_on->toDateString());

        // balanceOn() প্রকৃতি ধরে চিহ্ন ঠিক করে দেয়, তাই ক্রেডিট
        // প্রকৃতির খাতে ২০,০০০ লাভে ব্যালেন্স ২০,০০০ বাড়ে
        $this->assertSame('20000.0000', bcsub($after, $before, 4));
    }

    public function test_a_loss_moves_retained_earnings_the_other_way(): void
    {
        $this->trade(income: '10000.0000', expense: '25000.0000');

        $equity = StandardChart::find(StandardChart::RETAINED_EARNINGS);
        $before = $equity->balanceOn($this->year->ends_on->toDateString());

        $this->service()->close($this->year);

        $after = $equity->fresh()->balanceOn($this->year->ends_on->toDateString());

        // লোকসানে উল্টো দিকে — সঞ্চিত মুনাফা কমে
        $this->assertSame('-15000.0000', bcsub($after, $before, 4));
    }

    // ── খাতা মেলে ─────────────────────────────────────────────────────

    /**
     * বন্ধের দাখিলা ও খোলার দাখিলা — দুইটাই ভারসাম্যপূর্ণ।
     *
     * Posting engine অসম দাখিলা নেয় না, তাই এটা আসলে engine-এর পরীক্ষা
     * নয় — এটা পরীক্ষা যে আমরা লাইনগুলো ঠিকভাবে জোড়া দিয়েছি। ভুল হলে
     * close() ব্যতিক্রম ছুঁড়ত, আর বছর বন্ধ হত না।
     */
    public function test_both_year_end_entries_balance(): void
    {
        $this->trade(income: '50000.0000', expense: '30000.0000');

        $newYear = $this->service()->close($this->year);

        $this->assertNotNull($newYear);

        $lines = LedgerEntry::query()
            ->where('source_type', YearEndService::CLOSE_SOURCE)
            ->where('source_id', $this->year->id)
            ->get();

        $this->assertGreaterThan(0, $lines->count(), 'বন্ধের দাখিলাটাই বসেনি।');
        $this->assertSame(
            0,
            bccomp((string) $lines->sum('debit'), (string) $lines->sum('credit'), 4),
            'বন্ধের দাখিলা মেলে না।',
        );

        // খোলার কোনো দাখিলা নেই, আর থাকার কথাও নয় — লেজার একটানা,
        // তাই টেনে নেওয়ার সারি বসালে প্রতিটা সংখ্যা দ্বিগুণ হত
        $this->assertSame(0, LedgerEntry::query()->where('source_type', 'year_open')->count());
    }

    /**
     * নতুন বছরের প্রথম দিনে ট্রায়াল ব্যালেন্স মেলে।
     *
     * এটাই আসল পরীক্ষা: বছর বদলানোর পর খাতা এমন অবস্থায় থাকতে হবে যে
     * কেউ সেদিনই একটা ব্যালেন্স শিট বের করলে সেটা সঠিক হয়।
     */
    public function test_the_books_still_balance_on_the_first_day_of_the_new_year(): void
    {
        $this->trade(income: '50000.0000', expense: '30000.0000');

        $newYear = $this->service()->close($this->year);

        $row = LedgerEntry::query()
            ->whereDate('trx_date', '<=', $newYear->starts_on)
            ->selectRaw('SUM(debit) as d, SUM(credit) as c')
            ->first();

        $this->assertSame(0, bccomp((string) $row->d, (string) $row->c, 4), 'ট্রায়াল ব্যালেন্স মেলে না।');
    }

    // ── পক্ষভিত্তিক বকেয়া প্রতি পক্ষে টানা হয় ─────────────────────────

    /**
     * সরবরাহকারীর প্রদেয় নতুন বছরেও তার নামেই থাকে।
     *
     * এটাই সবচেয়ে সহজে ভুল হওয়ার জায়গা। খাতের মোট টানলে ট্রায়াল
     * ব্যালেন্স মিলত আর সবাই ভাবত কাজ হয়েছে — কিন্তু "প্রাণ আরএফএল-কে
     * কত দিতে হবে" প্রশ্নের উত্তর হারিয়ে যেত, আর ওটাই রোজ লাগে।
     */
    public function test_each_partys_balance_carries_forward_under_its_own_name(): void
    {
        $a = $this->supplier('Alpha', '30000.0000');
        $b = $this->supplier('Beta', '12000.0000');

        $newYear = $this->service()->close($this->year);

        $this->assertNotNull($newYear);

        /*
         * বছর বন্ধের পরেও প্রতিটা পক্ষের বকেয়া অপরিবর্তিত।
         *
         * প্রথম বাস্তবায়নে ১ জুলাই একটা "খোলা ব্যালেন্স" দাখিলা বসানো
         * হত, আর তাতে প্রতিটা সংখ্যা দ্বিগুণ হয়ে যেত — Alpha-র ৩০,০০০
         * হয়ে যেত ৬০,০০০। ট্রায়াল ব্যালেন্স তবু মিলত, কারণ দুই দিকই
         * দ্বিগুণ হত; অর্থাৎ সবচেয়ে স্বাভাবিক পরীক্ষাটা ভুলটা ঢেকে দিত।
         */
        foreach ([[$a, '30000.0000'], [$b, '12000.0000']] as [$supplier, $expected]) {
            $this->assertSame(
                0,
                bccomp($supplier->fresh()->payable(), $expected, 4),
                "{$supplier->name_en}-এর প্রদেয় বছর বদলে গিয়ে বদলে গেছে।",
            );
        }

        // আর পক্ষভিত্তিক সারিগুলো আলাদাই আছে — একটার সাথে আরেকটা মেশেনি
        $this->assertSame(
            2,
            LedgerEntry::query()
                ->where('party_type', Supplier::drillSourceType())
                ->distinct()
                ->count('party_id'),
        );
    }

    // ── নম্বর সিরিজ ───────────────────────────────────────────────────

    /**
     * reset_yearly চালু থাকলে ক্রম আবার ১ থেকে।
     *
     * কলামটা এতদিন সংরক্ষিত হত কিন্তু কেউ পড়ত না — বছর বদলানোর কোনো
     * ব্যবস্থাই ছিল না, তাই সেটার কোনো অর্থও ছিল না।
     */
    public function test_a_yearly_series_restarts_at_one(): void
    {
        $engine = app(NumberSeriesEngine::class);

        $engine->next('CUS');
        $engine->next('CUS');

        NumberSeries::query()->where('doc_type', 'CUS')->update(['reset_yearly' => true, 'start_number' => 1]);

        $newYear = $this->service()->close($this->year);

        $series = NumberSeries::query()
            ->where('doc_type', 'CUS')
            ->where('financial_year_id', $newYear->id)
            ->firstOrFail();

        $this->assertSame(1, $series->next_number);
    }

    public function test_a_continuous_series_keeps_counting(): void
    {
        $engine = app(NumberSeriesEngine::class);

        $engine->next('CUS');
        $engine->next('CUS');

        $before = NumberSeries::query()
            ->where('doc_type', 'CUS')
            ->where('financial_year_id', $this->year->id)
            ->firstOrFail();

        $before->update(['reset_yearly' => false]);

        $newYear = $this->service()->close($this->year);

        $after = NumberSeries::query()
            ->where('doc_type', 'CUS')
            ->where('financial_year_id', $newYear->id)
            ->firstOrFail();

        $this->assertSame($before->fresh()->next_number, $after->next_number);
    }

    /**
     * গত বছর ঠিক করা ছক নতুন বছরেও থাকে।
     *
     * না থাকলে প্রতি ১ জুলাই ব্যবহারকারীকে আবার সব সিরিজের উপসর্গ ও ছক
     * বসাতে হত — আর প্রথম কয়েকটা ডকুমেন্ট ভুল চেহারায় বেরিয়ে যেত।
     */
    public function test_the_format_the_user_chose_survives_the_year_change(): void
    {
        NumberSeries::query()
            ->where('doc_type', 'CUS')
            ->update(['prefix' => 'GRA', 'format' => '{PREFIX}/{YY}/{SEQ}', 'padding' => 6]);

        $newYear = $this->service()->close($this->year);

        $series = NumberSeries::query()
            ->where('doc_type', 'CUS')
            ->where('financial_year_id', $newYear->id)
            ->firstOrFail();

        $this->assertSame('GRA', $series->prefix);
        $this->assertSame('{PREFIX}/{YY}/{SEQ}', $series->format);
        $this->assertSame(6, $series->padding);
    }

    // ── তালা ──────────────────────────────────────────────────────────

    /**
     * খসড়া ভাউচার থাকলে বছর বন্ধ হয় না।
     *
     * ওগুলো কখনো পোস্ট হয়নি, তাই বছর বন্ধ হলে আর কখনো পোস্ট হতেও
     * পারবে না — কাজটা চুপচাপ হারিয়ে যেত। পোস্ট করা না বাতিল করা,
     * সেই সিদ্ধান্ত সিস্টেমের নয়।
     */
    public function test_a_year_with_unposted_drafts_cannot_be_closed(): void
    {
        Voucher::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => $this->year->id,
            'type' => 'journal',
            'document_no' => 'JRN-TEST-0001',
            'trx_date' => $this->year->starts_on->copy()->addMonth(),
            'narration' => 'draft',
            'amount' => '100.0000',
            'status' => DocumentStatus::DRAFT,
        ]);

        $this->expectException(ValidationException::class);

        $this->service()->close($this->year);
    }

    public function test_a_closed_year_cannot_be_closed_twice(): void
    {
        $this->service()->close($this->year);

        $this->expectException(ValidationException::class);

        $this->service()->close($this->year->fresh());
    }

    /**
     * বছরগুলো একে অন্যের উপর পড়ে না।
     *
     * পড়লে একই তারিখ দুই বছরে থাকত, আর FinancialYear::forDate() যেকোনো
     * একটা ফেরত দিত — একই দিনের দুইটা বিল দুই বছরে বসে যেত।
     */
    public function test_overlapping_years_are_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->close($this->year, [
            'starts_on' => $this->year->starts_on->copy()->addMonth()->toDateString(),
            'ends_on' => $this->year->ends_on->copy()->addYear()->toDateString(),
        ]);
    }

    // ── আগে দেখে নেওয়া ────────────────────────────────────────────────

    /**
     * preview কিছু বদলায় না।
     *
     * বছর বন্ধ করা ফেরানো যায় না, তাই আগে দেখে নেওয়ার পথটা নিরাপদ
     * হতেই হবে — "সেভ করে দেখি কী হয়" এখানে চলে না।
     */
    public function test_the_preview_changes_nothing(): void
    {
        $this->trade(income: '50000.0000', expense: '30000.0000');

        $before = LedgerEntry::query()->count();

        $preview = $this->service()->preview($this->year);

        $this->assertSame($before, LedgerEntry::query()->count());
        $this->assertSame('20000.0000', $preview['profit']);
        $this->assertFalse($this->year->fresh()->is_closed);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** কিছু বিক্রি ও কিছু খরচ — বছরের ভেতরে। */
    private function trade(string $income, string $expense): void
    {
        $cash = StandardChart::find(StandardChart::CASH_IN_HAND);
        $sales = StandardChart::find(StandardChart::SALES);
        $cost = StandardChart::find(StandardChart::DISCOUNT_GIVEN);

        $date = $this->year->starts_on->copy()->addMonths(3)->toDateString();

        app(PostingEngine::class)->post(
            sourceType: 'test_sale',
            sourceId: 1,
            trxDate: $date,
            lines: [
                ['account_id' => $cash->id, 'debit' => $income],
                ['account_id' => $sales->id, 'credit' => $income],
            ],
        );

        app(PostingEngine::class)->post(
            sourceType: 'test_expense',
            sourceId: 1,
            trxDate: $date,
            lines: [
                ['account_id' => $cost->id, 'debit' => $expense],
                ['account_id' => $cash->id, 'credit' => $expense],
            ],
        );
    }

    private function supplier(string $name, string $opening): Supplier
    {
        return app(SupplierService::class)->create([
            'name_en' => $name,
            'credit_limit' => 0,
            'credit_days' => 0,
            'opening_balance' => $opening,
            'opening_date' => $this->year->starts_on->toDateString(),
        ]);
    }
}
