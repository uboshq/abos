<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerMetrics;
use App\Modules\Finance\Dashboard\FinanceDashboard;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Person;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Dashboard\SalesDashboard;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ড্যাশবোর্ডের সংখ্যা খাতায় যা আছে তাই — অডিট ⛔১১ (৬ অক্টোবর ২০২৬, ড্যাশবোর্ডের ঘর)।
 *
 * ⛔ ধরা পড়েছিল:
 *  - বিক্রয়ের "মোট বকেয়া" আসলে নিশ্চিত বিলের মোট, আদায় বাদ যেত না; "সবচেয়ে বড় বকেয়া" বিলের মোটে সাজানো;
 *  - অর্থের "মূলধন এসেছে" খসড়াও গুনত;
 *  - মার্জিনে এ মাসের ফেরত বাদ যেত না;
 *  - হিসাবের সংখ্যা "সব শাখা"-তে শাখা-সীমিত মানুষের নাগাল মানত না।
 */
final class TheDashboardFiguresCountWhatIsReallyThereTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
    }

    public function test_the_sales_due_is_what_customers_still_owe_and_the_list_ranks_by_it(): void
    {
        $shop = Customer::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'code' => 'DUE-1', 'name_en' => 'Due shop', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true, 'credit_limit' => '1000000']);
        $before = $this->stat(SalesDashboard::dashboard()->stats, __('sales::dashboard.outstanding'));

        // ⓘ ১,২৫০ টাকার বিল, তার ১,০০০ নগদে — বাকি ২৫০
        app(DirectSaleService::class)->complete(
            ['customer_id' => $shop->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1', 'deposit' => '1000'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '125', 'rate' => '10', 'free_qty' => '0']],
        );

        $board = SalesDashboard::dashboard();
        $after = $this->stat($board->stats, __('sales::dashboard.outstanding'));
        $this->assertSame(Money::format(app(CustomerMetrics::class)->dues($this->owner, now()->toDateString())['amount']), $after,
            '⛔ "মোট বকেয়া" খাতার বকেয়া নয়।');
        $this->assertSame(0, bccomp(bcsub($this->number($after), $this->number($before), 2), '250', 2), '⛔ "মোট বকেয়া" আদায় বাদ দেয়নি (১,২৫০ নয়, ২৫০ বাড়ার কথা)।');

        $rows = $board->listings[0]->rows;
        $due = collect($board->listings[0]->columns)->firstWhere('key', 'due')['render'];
        $mine = $rows->first(fn ($i) => (int) $i->customer_id === (int) $shop->id);
        $this->assertNotNull($mine, 'নতুন বিলটা বড় বকেয়ার তালিকায় নেই — দাবি অন্ধ।');
        $this->assertSame(Money::format('250'), $due($mine), '⛔ তালিকা বিলের মোট দেখায়, বাকি নয়।');
        $dues = $rows->map(fn ($i) => (float) str_replace(',', '', $due($i)))->all();
        $sorted = $dues;
        rsort($sorted);
        $this->assertSame($sorted, $dues, '⛔ তালিকা বাকি ধরে বড় থেকে ছোটতে সাজানো নয়।');
    }

    public function test_a_draft_capital_entry_is_not_money_that_came_in(): void
    {
        $before = $this->stat(FinanceDashboard::dashboard()->stats, __('finance::dashboard.capital_in'));
        $person = Person::query()->firstOrCreate(['company_id' => $this->company->id, 'name_en' => 'Draft partner'], ['code' => 'P-DRAFT']);
        app(CapitalService::class)->record(['person_id' => $person->id, 'contributor_type' => CapitalEntry::OWNER,
            'entry_type' => CapitalEntry::CONTRIBUTION, 'trx_date' => now()->toDateString(), 'amount' => '777777']);

        $this->assertSame($before, $this->stat(FinanceDashboard::dashboard()->stats, __('finance::dashboard.capital_in')),
            '⛔ খসড়া মূলধন "মূলধন এসেছে"-তে গোনা হলো।');
    }

    public function test_a_return_comes_off_the_months_profit(): void
    {
        $shop = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $shop->forceFill(['credit_limit' => '1000000'])->save();
        $product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $sale = app(DirectSaleService::class)->complete(
            ['customer_id' => $shop->id, 'warehouse_id' => $warehouse->id, 'own_transport' => '1', 'payment_term' => 'credit', 'credit_period_days' => 15],
            [['product_id' => $product->id, 'qty' => '50', 'rate' => '10', 'free_qty' => '0']],
        );
        $sold = fn () => $this->number(collect(\App\Modules\Sales\Dashboard\SalesCharts::all())
            ->first(fn ($p) => $p instanceof Breakdown && $p->label === __('sales::dashboard.profit'))->parts[0]['value']);
        $before = $sold();

        $invoice = $sale['invoice']->fresh('lines');
        $returns = app(SalesReturnService::class);
        $returns->confirm($returns->create(
            ['customer_id' => $shop->id, 'warehouse_id' => $warehouse->id, 'sales_invoice_id' => $invoice->id, 'trx_date' => now()->toDateString(),
                'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id')],
            [['product_id' => $product->id, 'sales_invoice_line_id' => $invoice->lines->first()->id, 'qty' => '10']],
        ));

        $this->assertSame(0, bccomp(bcsub($before, $sold(), 2), '100', 2), '⛔ এ মাসের ফেরত (১০ × ১০) লাভের বিক্রি থেকে বাদ যায়নি।');
    }

    public function test_the_ledger_figures_stay_inside_the_reach_under_all_branches(): void
    {
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => null, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $a->id,
        ]);

        $payable = fn (User $who) => $this->as($who, fn () => app(AccountsFacts::class)->balanceOfCode(StandardChart::PAYABLE));
        $clerkBefore = $payable($clerk);
        $ownerBefore = $payable($this->owner);

        CompanyContext::set($this->company->id, $b->id);
        $this->actingAs($this->owner);
        app(PostingEngine::class)->post(sourceType: 'test:dash-reach', sourceId: 1, trxDate: now(), branchId: $b->id, lines: [
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => '4444'],
            ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'credit' => '4444'],
        ]);

        $this->assertSame(0, bccomp($payable($clerk), $clerkBefore, 4), '⛔ A-তে সীমিত মানুষের "সব শাখা"-র দেনায় শাখা B-র অঙ্ক এল।');
        $this->assertSame(0, bccomp(bcsub($payable($this->owner), $ownerBefore, 4), '4444', 4), 'মালিকের দেনায় B-র অঙ্ক আসেনি — দাবি অন্ধ।');
    }

    // ── যন্ত্রপাতি ──

    /** @param  list<Stat>  $stats */
    private function stat(array $stats, string $label): string
    {
        return (string) collect($stats)->first(fn (Stat $s) => $s->label === $label)?->value;
    }

    private function number(string $formatted): string
    {
        return str_replace(',', '', $formatted) ?: '0';
    }

    private function as(User $who, \Closure $work): string
    {
        $who->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => null])->save();
        CompanyContext::set($this->company->id, null);
        $this->actingAs($who->fresh());
        app(DataScope::class)->forget();

        return (string) $work();
    }
}
