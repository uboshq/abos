<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ নগদ প্রবাহ কেবল প্রত্যক্ষ পদ্ধতিতে ছিল; নিট লাভ থেকে পরিচালনার নগদে পৌঁছানোর মিলন ছিল না (ERP ১০০%-এর হিসাবের ফাঁক
 * #৩৪, ২০ অক্টোবর ২০২৬)।
 *
 * <p>IAS 7.18(b), 7.20: লাভ থেকে শুরু, নগদহীন খরচ (অবচয়) ফেরত যোগ, পাওনা-মজুদ-দেনার বদল — শেষে পরিচালনার নগদ, যা প্রত্যক্ষ
 * পদ্ধতির পরিচালনার যোগের সাথে মেলে। ব্যাংক আর নিরীক্ষক এই মিলন দিয়েই দেখেন লাভটা নগদে এল কি না।
 */
final class TheCashFlowNeverStartedFromProfitTest extends TestCase
{
    use RefreshDatabase;

    private string $from;

    private string $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    public function test_profit_plus_noncash_items_plus_working_capital_is_the_operating_cash_and_agrees_with_the_direct_method(): void
    {
        $before = $this->lines();

        $till = Account::query()->findOrFail(app(CashTillService::class)->ensurePrimaryTill()->account_id)->id;
        $sales = $this->leaf(StandardChart::SALES);
        $expense = (int) Account::query()->where('code', '5202')->value('id');
        $receivable = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $payable = (int) StandardChart::find(StandardChart::PAYABLE)->id;
        $inventory = $this->leaf(StandardChart::INVENTORY);
        $depreciation = $this->leaf(StandardChart::DEPRECIATION_EXPENSE);
        $accumulated = $this->leaf(StandardChart::ACCUMULATED_DEPRECIATION);
        $customer = ['party_type' => Customer::drillSourceType(), 'party_id' => Customer::query()->orderBy('id')->firstOrFail()->id];
        $supplier = ['party_type' => Supplier::drillSourceType(), 'party_id' => Supplier::query()->orderBy('id')->firstOrFail()->id];

        $this->book([$till, []], [StandardChart::find(StandardChart::OWNER_CAPITAL)->id, []], '50000'); // অর্থায়ন, পরিচালনা নয়
        $this->book([$till, []], [$sales, []], '30000');                 // নগদে বিক্রি
        $this->book([$receivable, $customer], [$sales, []], '20000');    // বাকিতে বিক্রি — টাকা নড়েনি
        $this->book([$till, []], [$receivable, $customer], '5000');      // গ্রাহক দিলেন
        $this->book([$expense, []], [$till, []], '4000.25');             // নগদে খরচ
        $this->book([$depreciation, []], [$accumulated, []], '1000');    // অবচয় — নগদহীন
        $this->book([$inventory, []], [$payable, $supplier], '8000');    // বাকিতে মাল কেনা
        $this->book([$payable, $supplier], [$till, []], '3000');         // সরবরাহকারীকে দেওয়া

        $after = $this->lines();
        $delta = fn (string $key) => bcsub($after[$key], $before[$key], 4);

        $this->assertSame(0, bccomp($delta('cf_ind_profit'), '44999.75', 4), '⛔ নিট লাভ ৩০,০০০ + ২০,০০০ − ৪,০০০.২৫ − ১,০০০ নয়');
        $this->assertSame(0, bccomp($delta('cf_ind_noncash'), '1000', 4), '⛔ অবচয় ফেরত যোগ হলো না');
        $this->assertSame(0, bccomp($delta('cf_ind_receivable'), '-15000', 4), '⛔ পাওনার বৃদ্ধি (২০,০০০ − ৫,০০০) বিয়োগ হলো না');
        $this->assertSame(0, bccomp($delta('cf_ind_inventory'), '-8000', 4), '⛔ মজুদের বৃদ্ধি বিয়োগ হলো না');
        $this->assertSame(0, bccomp($delta('cf_ind_payable'), '5000', 4), '⛔ দেনার বৃদ্ধি (৮,০০০ − ৩,০০০) যোগ হলো না');
        $this->assertSame(0, bccomp($delta('cf_ind_operating'), '27999.75', 4), '⛔ পরোক্ষ পদ্ধতির পরিচালনার নগদ ৩০,০০০ + ৫,০০০ − ৪,০০০.২৫ − ৩,০০০ নয়');

        // ⛔ দুই পদ্ধতি এক অঙ্কে — আর প্রত্যক্ষটা রিপোর্টের নিজের পরিচালনার সারিগুলোর যোগ, মালিকের টাকা বাদে
        $this->assertSame(0, bccomp($after['cf_ind_operating'], $after['cf_ind_direct'], 4), '⛔ পরোক্ষ আর প্রত্যক্ষ পদ্ধতি মেলে না');
        $this->assertSame(0, bccomp($after['cf_ind_direct'], $this->directOperatingRows(), 4), '⛔ মিলনের প্রত্যক্ষ সারি রিপোর্টের পরিচালনার যোগ নয়');

        $html = (string) $this->get(route('accounts.report.final.cash_flow', ['from' => $this->from, 'to' => $this->to]))->assertOk()->getContent();
        $this->assertStringContainsString(__('accounts::message.cf_ind_operating'), $html, '⛔ পাতায় পরোক্ষ মিলন নেই');
        $this->assertStringContainsString(Money::format($after['cf_ind_operating']), $html);
    }

    /** @return array<string, string> মিলনের সারি, ভাষার চাবি ধরে */
    private function lines(): array
    {
        $engine = app(ReportEngine::class);
        $result = $engine->run('accounts.cash_flow', ['from' => $this->from, 'to' => $this->to], 1, 500);
        $byLabel = collect((($engine->get('accounts.cash_flow')->summary)($result->totals, $result->filters))['lines'] ?? [])->pluck('value', 'label');

        $out = [];
        foreach (['cf_ind_profit', 'cf_ind_noncash', 'cf_ind_receivable', 'cf_ind_inventory', 'cf_ind_payable', 'cf_ind_other', 'cf_ind_operating', 'cf_ind_direct'] as $key) {
            $value = $byLabel->get(__('accounts::message.'.$key));
            $this->assertNotNull($value, 'মিলনের সারি নেই: '.$key);
            $out[$key] = (string) $value;
        }

        return $out;
    }

    private function directOperatingRows(): string
    {
        $result = app(ReportEngine::class)->run('accounts.cash_flow', ['from' => $this->from, 'to' => $this->to], 1, 500);

        return collect($result->rows)->where('section', __('accounts::message.cf_operating'))
            ->reduce(fn (string $sum, $row) => bcadd($sum, (string) $row['net'], 4), '0');
    }

    private function leaf(string $code): int
    {
        $account = StandardChart::find($code);
        $this->assertNotNull($account, 'ছকে নেই: '.$code);
        if (! $account->is_group) {
            return (int) $account->id;
        }
        $child = Account::query()->where('parent_id', $account->id)->where('is_group', false)->orderBy('code')->first();
        $this->assertNotNull($child, 'পাতা-খাত নেই: '.$code);

        return (int) $child->id;
    }

    /** @param  array{0: int, 1: array<string, mixed>}  $debit  @param  array{0: int, 1: array<string, mixed>}  $credit */
    private function book(array $debit, array $credit, string $amount): void
    {
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: $this->to, lines: [
            ['account_id' => $debit[0], 'debit' => $amount, ...$debit[1]],
            ['account_id' => $credit[0], 'credit' => $amount, ...$credit[1]],
        ]);
    }
}
