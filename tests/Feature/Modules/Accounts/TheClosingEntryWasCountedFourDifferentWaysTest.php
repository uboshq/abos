<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\FixedAsset;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Services\BalanceSheetService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * বন্ধের দাখিলাটা চার জায়গায় চার রকম গোনা হত।
 *
 * ── ⓘ অডিট, ২০ সেপ্টেম্বর ২০২৬ ───────────────────────────────────────
 * বছর বন্ধ করার দাখিলা কোথায় গোনা হবে আর কোথায় হবে না — নিয়মটা এক
 * জায়গায় লেখা ছিল না। ⛔ ফল: একই বছরের লাভ স্থিতিপত্রে দ্বিগুণ, আর
 * লাভ-ক্ষতির হিসাবে শূন্য।
 *
 * ── ⚠️ আর তৃতীয় ভুলটা আলাদা, কিন্তু একই পরিবারের ─────────────────────
 * স্থায়ী সম্পত্তির নিবন্ধন আর বিদায় — দুইটাই একই চাবিতে খাতায় বসত,
 * তাই যে সম্পদের টাকার উৎস লেখা আছে **সেটা আর বিক্রিই করা যেত না**।
 * ⓘ পুরনো পরীক্ষা ধরেনি, কারণ তার সম্পদগুলো "টাকা আগেই দেওয়া" বলে
 * নিবন্ধিত হত — তখন নিবন্ধনের কোনো দাখিলাই বসত না।
 */
final class TheClosingEntryWasCountedFourDifferentWaysTest extends TestCase
{
    use RefreshDatabase;

    private FinancialYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->year = FinancialYear::query()->where('is_current', true)->firstOrFail();
    }

    /**
     * ⛔ যে সম্পদের টাকার উৎস লেখা আছে, সেটাও বিক্রি করা যায়।
     *
     * ⚠️ আগে হত না: নিবন্ধন আর বিদায় একই চাবিতে বসায় পোস্টিং ইঞ্জিন
     * বিদায়টা আটকে দিত, আর গোটা লেনদেন ফিরে যেত — বিক্রির টাকা খাতায়
     * উঠত না, সম্পদ স্থিতিপত্রে থেকে যেত, আর অবচয় বসতেই থাকত।
     */
    public function test_an_asset_whose_money_came_from_somewhere_can_still_be_sold(): void
    {
        $asset = $this->assetBoughtWithCash();

        // ⓘ নিবন্ধনের দাখিলাটা সত্যিই বসেছে — নাহলে পরীক্ষাটা কিছুই প্রমাণ করত না
        $this->assertTrue(
            LedgerEntry::query()
                ->where('source_type', FixedAsset::drillSourceType())
                ->where('source_id', $asset->id)
                ->exists(),
            'টাকার উৎস দেওয়া সত্ত্বেও নিবন্ধনের দাখিলা বসেনি — পরীক্ষাটা ফাঁকা।',
        );

        $sold = app(FixedAssetService::class)->dispose(
            asset: $asset,
            amount: '40000',
            intoAccountId: app(CashTillService::class)->ensurePrimaryTill()->account_id,
            date: $this->year->starts_on->copy()->addMonths(6)->toDateString(),
        );

        $this->assertSame(FixedAsset::DISPOSED, $sold->status);

        $this->assertTrue(
            LedgerEntry::query()
                ->where('source_type', FixedAsset::disposalSourceType())
                ->where('source_id', $asset->id)
                ->exists(),
            'বিদায়ের দাখিলা খাতায় নেই — বিক্রির টাকা কোথাও ওঠেনি।',
        );
    }

    /**
     * ⭐ বছর বন্ধ করার পরেও স্থিতিপত্র মেলে।
     *
     * ⚠️ আগে মিলত না, আর বেমিলের পরিমাণ ছিল ঠিক ওই বছরের লাভ — কারণ
     * লাভটা সঞ্চিত মুনাফায় একবার, আর "চলতি বছরের ফল" সারিতে আরেকবার।
     */
    public function test_the_sheet_still_agrees_after_the_year_is_closed(): void
    {
        $this->trade(income: '50000', expense: '30000');

        $before = app(BalanceSheetService::class)->build($this->year->ends_on->toDateString());
        $this->assertTrue($before['agrees'], 'বন্ধ করার আগেই স্থিতিপত্র মিলছে না।');
        $this->assertSame(0, bccomp($before['profit'], '20000', 4));

        app(YearEndService::class)->close($this->year);

        $after = app(BalanceSheetService::class)->build($this->year->ends_on->toDateString());

        $this->assertTrue(
            $after['agrees'],
            'বছর বন্ধের পর স্থিতিপত্র মেলে না — বেমিল '.$after['difference'],
        );

        // ⓘ ফলটা এখন সঞ্চিত মুনাফার ঘরে; "চলতি বছরের ফল" সারিতে আর নয়
        $this->assertSame(0, bccomp($after['profit'], '0', 4),
            'বন্ধের পরেও চলতি বছরের ফল আলাদা করে দেখানো হচ্ছে — তাহলে লাভ দুইবার গোনা।');
    }

    /**
     * ⭐ বছর আবার খুললে লাভ দ্বিগুণ হয় না।
     *
     * ⚠️ উলটানো সারিগুলোর নাম `year_close:reversal`, আর পুরনো ছাঁকনি
     * কেবল `year_close` বাদ দিত — তাই দুইটাই গোনা হত।
     */
    public function test_reopening_the_year_does_not_double_the_profit(): void
    {
        $this->trade(income: '50000', expense: '30000');

        $service = app(YearEndService::class);
        $service->close($this->year);
        $service->reopen($this->year->fresh(), User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $sheet = app(BalanceSheetService::class)->build($this->year->ends_on->toDateString());

        $this->assertSame(0, bccomp($sheet['profit'], '20000', 4),
            'বছর আবার খোলার পর ফল '.$sheet['profit'].' — ২০,০০০ হওয়ার কথা।');

        $this->assertTrue($sheet['agrees'], 'আবার খোলার পর স্থিতিপত্র মেলে না।');
    }

    /**
     * ⭐ বন্ধ বছরের ফল তিন পর্দাতেই এক কথা বলে।
     *
     * ⛔ আগে বলত না: স্থিতিপত্র ২০,০০০, `netResult()` ০, আর লাভ-ক্ষতির
     * রিপোর্ট ০ — একই বছরের তিনটা উত্তর।
     */
    public function test_a_closed_year_reports_the_same_result_everywhere(): void
    {
        $this->trade(income: '50000', expense: '30000');

        $service = app(YearEndService::class);
        $service->close($this->year);

        $this->assertSame(0, bccomp($service->netResult($this->year->fresh()), '20000', 4),
            'বন্ধ বছরের ফল শূন্য দেখাচ্ছে — ওই বছরে কত লাভ হয়েছিল, সেটাই মুছে গেল।');
    }

    /**
     * ⭐ তিন বছরের পুরনো একটা ভ্যানও ব্যবস্থায় তোলা যায়।
     *
     * ⛔ আগে যেত না: কেনার তারিখে দাখিলা বসানোর চেষ্টা হত, আর
     * ওই তারিখ কোনো চালু অর্থবছরে না পড়ায় সব ফিরে যেত — **সম্পদের
     * সারিটাই তৈরি হত না**।
     *
     * ⭐ আর তিন বছরের ক্ষয়টাও সাথে আসে, তাই খাতায় জিনিসটা নতুন
     * দেখায় না। ⚠️ ক্ষয়টা খরচে যায় না — ওটা আগের বছরগুলোর।
     */
    public function test_a_three_year_old_van_can_be_brought_in(): void
    {
        $spentBefore = $this->expenseTotal();

        $asset = app(FixedAssetService::class)->register([
            'name' => 'Old Van',
            'acquired_on' => $this->year->starts_on->copy()->subYears(3)->toDateString(),
            'cost' => '100000',
            'salvage' => '0',
            'life_months' => 60,
            'asset_account_id' => Account::query()->postable()->where('code', '1202')->value('id'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)?->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)?->id,
            'funded_by' => FixedAssetService::FUNDED_OPENING,
            'opening_accumulated' => '60000',
        ]);

        $this->assertNotNull($asset->fresh(), 'সম্পদের সারিটাই তৈরি হয়নি।');

        // ⭐ খাতায় জিনিসটার দাম এখন বাকি দামই — ১,০০,০০০ বিয়োগ ৬০,০০০
        $this->assertSame(0, bccomp($asset->fresh()->bookValue(), '40000', 4),
            'পুরনো ক্ষয়টা খাতায় ওঠেনি — ভ্যানটা নতুন হিসেবেই ঢুকল।');

        // ⚠️ আর ওই ক্ষয় এই বছরের খরচ নয়
        $this->assertSame($spentBefore, $this->expenseTotal(),
            'পুরনো ক্ষয়টা এই বছরের খরচে বসেছে — প্রথম মাসেই লাভ খেয়ে ফেলত।');
    }

    /** খরচের খাতগুলোর যোগফল। */
    private function expenseTotal(): string
    {
        return (string) LedgerEntry::query()
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('accounts.type', Account::EXPENSE)
            ->sum(DB::raw('ledger_entries.debit - ledger_entries.credit'));
    }

    /**
     * ⭐ বছর আবার খুলে তারপর আবার বন্ধ করা যায় — তিন ধাপেই খাতা মেলে।
     *
     * ⛔ আগে যেত না: বন্ধ করার দিন পরের বছরটা তৈরি হয়, আর খোলার
     * সময় সেটা মোছা হয় না — তাই দ্বিতীয়বার বন্ধ করতে গেলে নিজের
     * তৈরি বছরটাকেই "সংঘর্ষ" বলত।
     */
    public function test_a_reopened_year_can_be_closed_again(): void
    {
        $this->trade(income: '50000', expense: '30000');

        $service = app(YearEndService::class);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $service->close($this->year);
        $this->assertTrue($this->sheet()['agrees'], 'প্রথম বন্ধের পর খাতা মেলে না।');

        $service->reopen($this->year->fresh(), $owner);
        $this->assertTrue($this->sheet()['agrees'], 'আবার খোলার পর খাতা মেলে না।');

        // ⭐ তৃতীয় ধাপ — এই ডাকটাই আগে ব্যতিক্রম ছুঁড়ত
        $service->close($this->year->fresh());

        $sheet = $this->sheet();

        $this->assertTrue($sheet['agrees'], 'আবার বন্ধ করার পর খাতা মেলে না — বেমিল '.$sheet['difference']);

        // ⚠️ দুইবার বন্ধ হলেও লাভ দ্বিগুণ হয় না
        $this->assertSame(0, bccomp(app(YearEndService::class)->netResult($this->year->fresh()), '20000', 4));

        // ⓘ আর একই সীমার দুইটা বছর তৈরি হয়নি
        $this->assertSame(1, FinancialYear::query()
            ->whereDate('starts_on', $this->year->ends_on->copy()->addDay()->toDateString())
            ->count(), 'একই তারিখে দুইটা অর্থবছর বসেছে।');
    }

    /** স্থিতিপত্র, বছরের শেষ দিনে। */
    private function sheet(): array
    {
        return app(BalanceSheetService::class)->build($this->year->ends_on->toDateString());
    }

    /** টাকা দিয়ে কেনা একটা সম্পদ — উৎস লেখা আছে, তাই নিবন্ধনেই দাখিলা বসে। */
    private function assetBoughtWithCash(): FixedAsset
    {
        $cash = app(CashTillService::class)->ensurePrimaryTill()->account;

        return app(FixedAssetService::class)->register([
            'name' => 'Delivery Van',
            'acquired_on' => $this->year->starts_on->copy()->addMonth()->toDateString(),
            'cost' => '100000',
            'salvage' => '0',
            'life_months' => 60,
            /* ⚠️ `1200` একটা দল, ঘর নয় — দলে টাকা বসানো যায় না।
               ⓘ যানবাহন (`1202`) তার সন্তান, আর গাড়িই তো কেনা হচ্ছে। */
            'asset_account_id' => Account::query()->postable()->where('code', '1202')->value('id'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)?->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)?->id,
            'funded_by' => FixedAssetService::FUNDED_MONEY,
            'funding_account_id' => $cash->id,
        ]);
    }

    /** ৫০,০০০ বিক্রি আর ৩০,০০০ খরচ — বছরের ভেতরে। */
    private function trade(string $income, string $expense): void
    {
        $cash = app(CashTillService::class)->ensurePrimaryTill()->account;
        $sales = StandardChart::find(StandardChart::SALES);
        $spent = StandardChart::find(StandardChart::DISCOUNT_GIVEN);

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
            sourceType: 'test_spend',
            sourceId: 1,
            trxDate: $date,
            lines: [
                ['account_id' => $spent->id, 'debit' => $expense],
                ['account_id' => $cash->id, 'credit' => $expense],
            ],
        );
    }
}
