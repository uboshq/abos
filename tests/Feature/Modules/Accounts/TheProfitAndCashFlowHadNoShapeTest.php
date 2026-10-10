<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportResult;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * লাভ-ক্ষতিতে মোট মুনাফা ছিল না, আর নগদ প্রবাহ নিজের খাতের মধ্যে টাকা সরানোকেও আয়-ব্যয় গুনত — পুরো-ERP পুনঃঅডিট,
 * ৯ অক্টোবর ২০২৬ (হিসাব, ঠিক ৭; IAS 1 / IAS 7)।
 *
 * ছোট জানা খাতা (ফাঁকা দিনে, কেবল এই কাগজগুলো):
 *   বিক্রয় ১০,০০০ নগদে · বিক্রীত পণ্যের ব্যয় ৬,০০০ (মজুদ থেকে) · ভাড়া ১,০০০ নগদে · সুদ আয় ৫০০ নগদে
 *   নগদ থেকে ব্যাংকে ৩,০০০ · আসবাব কেনা ২,০০০ নগদে · মালিকের মূলধন ৪,০০০ নগদে
 * ⭐ লাভ-ক্ষতি: মোট মুনাফা ৪,০০০ (১০,০০০ − ৬,০০০); নিট ৩,৫০০।
 * ⭐ নগদ প্রবাহ: পরিচালন +৯,৫০০ (১০,০০০ − ১,০০০ + ৫০০), বিনিয়োগ −২,০০০, অর্থায়ন +৪,০০০; ব্যাংকে জমা কোথাও নেই।
 */
final class TheProfitAndCashFlowHadNoShapeTest extends TestCase
{
    use RefreshDatabase;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        // ⓘ ডেমোর কোনো দাখিলা নেই এমন একটা দিন — দাবিগুলো কেবল এই খাতার
        $this->day = now()->toDateString();
        LedgerEntry::query()->where('trx_date', $this->day)->exists() && $this->day = now()->subDay()->toDateString();
        $this->assertFalse(LedgerEntry::query()->where('trx_date', $this->day)->exists(), 'ফাঁকা দিন পাওয়া গেল না।');

        $cash = app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $bank = Account::query()->create([
            'code' => StandardChart::BANK.'-PL', 'name_en' => 'Shape Bank', 'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET, 'nature' => Account::DEBIT, 'money_kind' => Account::BANK, 'is_active' => true,
        ])->id;
        $id = fn (string $code) => $this->leaf($code)->id;

        foreach ([
            [[$cash, '10000', '0'], [$id(StandardChart::SALES), '0', '10000']],
            [[$id(StandardChart::COST_OF_GOODS_SOLD), '6000', '0'], [$id(StandardChart::INVENTORY), '0', '6000']],
            [[$id(StandardChart::RENT), '1000', '0'], [$cash, '0', '1000']],
            [[$cash, '500', '0'], [$id(StandardChart::INTEREST_INCOME), '0', '500']],
            [[$bank, '3000', '0'], [$cash, '0', '3000']],
            [[$id(StandardChart::FIXED_ASSETS), '2000', '0'], [$cash, '0', '2000']],
            [[$cash, '4000', '0'], [$id(StandardChart::OWNER_CAPITAL), '0', '4000']],
        ] as $n => $lines) {
            app(PostingEngine::class)->post(sourceType: 'test:shape', sourceId: $n + 1, trxDate: $this->day,
                lines: array_map(fn ($l) => ['account_id' => $l[0], 'debit' => $l[1], 'credit' => $l[2]], $lines));
        }
    }

    public function test_the_profit_and_loss_shows_cost_of_sales_and_gross_profit(): void
    {
        $result = $this->report('accounts.profit_loss');
        $rows = collect($result->rows);

        $this->assertSame(0, bccomp((string) $result->totals['gross'], '4000', 2), '⛔ মোট মুনাফা (বিক্রয় − বিক্রীত পণ্যের ব্যয়) ৪,০০০ নয়।');
        $this->assertSame(
            [__('accounts::message.pl_revenue'), __('accounts::message.pl_cost_of_sales'), __('accounts::message.pl_other_income'), __('accounts::message.pl_expenses')],
            $rows->pluck('section')->unique()->values()->all(),
            '⛔ লাভ-ক্ষতির ভাগ বা তাদের ক্রম ঠিক নয়।',
        );
        $this->assertSame(__('accounts::message.pl_cost_of_sales'), $rows->firstWhere('account_id', $this->leaf(StandardChart::COST_OF_GOODS_SOLD)->id)['section']);

        $summary = (app(ReportEngine::class)->get('accounts.profit_loss')->summary)($result->totals);
        $this->assertSame(0, bccomp((string) $summary['value'], '3500', 2), 'নিট লাভ বদলে গেল।');
        $this->assertSame(__('accounts::message.gross_profit'), $summary['lines'][0]['label']);

        $this->get(route('accounts.report.show', ['slug' => 'profit-loss', 'from' => $this->day, 'to' => $this->day]))
            ->assertOk()->assertSee(__('accounts::message.gross_profit'))->assertSee('4,000');
    }

    public function test_the_cash_flow_leaves_out_own_transfers_and_splits_into_three(): void
    {
        $result = $this->report('accounts.cash_flow');
        $bySection = collect($result->rows)->groupBy('section')
            ->map(fn ($rows) => $rows->reduce(fn ($sum, $r) => bcadd($sum, (string) $r['net'], 2), '0'));

        $this->assertSame(0, bccomp((string) ($bySection[__('accounts::message.cf_operating')] ?? '0'), '9500', 2), '⛔ পরিচালন নগদ ৯,৫০০ নয়।');
        $this->assertSame(0, bccomp((string) ($bySection[__('accounts::message.cf_investing')] ?? '0'), '-2000', 2), '⛔ বিনিয়োগ নগদ −২,০০০ নয়।');
        $this->assertSame(0, bccomp((string) ($bySection[__('accounts::message.cf_financing')] ?? '0'), '4000', 2), '⛔ অর্থায়ন নগদ ৪,০০০ নয়।');

        // ⛔ নগদ থেকে ব্যাংকে ৩,০০০ — না ঢোকা, না বেরোনো
        $this->assertSame(0, bccomp((string) $result->totals['money_in'], '14500', 2), '⛔ নিজের খাতের মধ্যে স্থানান্তর "ঢুকল"-এ গোনা হলো।');
        $this->assertSame(0, bccomp((string) $result->totals['money_out'], '3000', 2), '⛔ নিজের খাতের মধ্যে স্থানান্তর "বেরোল"-এ গোনা হলো।');

        $this->get(route('accounts.report.show', ['slug' => 'cash-flow', 'from' => $this->day, 'to' => $this->day]))
            ->assertOk()->assertSee(__('accounts::message.cf_investing'));
        // ⓘ রপ্তানি আর ছাপার পথ — একই কোয়েরি, পুরোটা
        $this->assertNotEmpty(iterator_to_array(app(ReportEngine::class)->stream('accounts.cash_flow', ['from' => $this->day, 'to' => $this->day]), false));
    }

    private function report(string $key): ReportResult
    {
        return app(ReportEngine::class)->run($key, ['from' => $this->day, 'to' => $this->day], 1, 500);
    }

    private function leaf(string $code): Account
    {
        $root = StandardChart::find($code);

        return $root->is_group
            ? Account::query()->postable()->whereKey($root->selfAndDescendants()->pluck('id'))->orderBy('code')->firstOrFail()
            : $root;
    }
}
