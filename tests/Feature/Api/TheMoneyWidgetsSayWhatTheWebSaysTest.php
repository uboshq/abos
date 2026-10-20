<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ cb-র ছয় উইজেটের সার্ভার-অংশ — যা ওয়েব বলে, উইজেটও তাই বলে (উইজেটের নকশা ১৯ অক্টোবর; মালিকের আদেশ, ২০ অক্টোবর ২০২৬;
 * [[MoneyInHandSummary]], [[CreditLimitsSummary]], [[ChequesSummary]], [[ExpensesSummary]], [[ProfitSummary]],
 * [[BankReconcileSummary]])।
 *
 * দাবি (ফোনের আসল দরজা `GET /api/v1/widgets/summary` দিয়ে):
 *  - টাকা হাতে = ফোনের "আজ কেমন গেল"-এর টাকার ঘর, অঙ্কে অঙ্কে;
 *  - লাভ কেবল নির্বাহী-চাবিতে, টাকা হাতে কেবল টিলের চাবিতে — চাবি নেই মানে key নেই;
 *  - খরচ: ১,০০০ টাকার ভাড়া মোটে ১,০০০ বাড়ায়, আর বেতনের খাতে বা বেতনের রানের দাখিলা মোটেও নয়, সারিতেও নয়;
 *  - লাভ: একটা বিক্রি বিক্রির ঘর বিলের মূল্যে আর মালের দাম স্তরের দামে বাড়ায়; মোট লাভ = বিক্রি − মালের দাম;
 *  - বাকির সীমা: সীমার ৯৫% ব্যবহার করা গ্রাহক "নাম · পয়েন্ট · শাখা" নামে আসে, ৫০% ব্যবহার করা আসে না;
 *  - চেক: আজকের হাতের চেক মোটে আর সারিতে, ফেরত চেক "bounced", কাল-তারিখের চেক নয়;
 *  - ব্যাংক মেলানো: ব্যাংকে একটা পাকা দাখিলা না-মেলানো সারি এক বাড়ায়।
 */
final class TheMoneyWidgetsSayWhatTheWebSaysTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_money_in_hand_is_the_today_doors_money_figure_to_the_paisa(): void
    {
        $widget = $this->widgets($this->owner)['money_in_hand'] ?? null;
        $today = $this->as($this->owner)->getJson('/api/v1/dashboard/today')->assertOk()->json('money');

        $this->assertNotNull($widget, 'প্রস্তুতিটাই ভুল — মালিক টাকা হাতে পান না');
        foreach (['cash', 'mfs', 'bank', 'transit'] as $part) {
            $this->assertSame($today[$part], $widget[$part], "⛔ {$part}: উইজেট আর আজকের পাতা আলাদা কথা বলে");
        }
        $this->assertSame($today['amount'], $widget['total']);
    }

    public function test_a_key_is_absent_without_its_permission(): void
    {
        $clerk = $this->person(['accounts.report', 'accounts.reconciliation.view']);
        $widgets = $this->widgets($clerk);

        $this->assertArrayNotHasKey('profit', $widgets, '⛔ নির্বাহী-চাবি ছাড়াই লাভ গেল — নকশা বলে "শুধু মালিক"');
        $this->assertArrayNotHasKey('money_in_hand', $widgets, '⛔ টিলের চাবি ছাড়াই হাতের টাকা গেল');
        $this->assertArrayHasKey('expenses', $widgets, 'প্রস্তুতিটাই ভুল');
        $this->assertArrayHasKey('bank_reconcile', $widgets, 'প্রস্তুতিটাই ভুল');
    }

    public function test_expenses_count_the_rent_and_never_the_staff_pay(): void
    {
        $before = $this->widgets($this->owner)['expenses'];

        $this->postLines([StandardChart::RENT => ['debit' => '1000']], 'test.rent');
        // ⛔ বেতনের খাতে — হাতের জাবেদায়, আর বেতনের রানের দাখিলায় অন্য খাতে
        $this->postLines([StandardChart::SALARY_EXPENSE => ['debit' => '50000']], 'test.salary_by_hand');
        $this->postLines([StandardChart::RENT => ['debit' => '7000']], 'payroll_run');

        $after = $this->widgets($this->owner)['expenses'];

        $this->assertSame(0, bccomp(bcsub($after['total'], $before['total'], 4), '1000', 4),
            '⛔ খরচের মোটে বেতন ঢুকেছে, বা ভাড়া ঢোকেনি');
        $salaryName = StandardChart::find(StandardChart::SALARY_EXPENSE)->name();
        $this->assertNotContains($salaryName, array_column($after['rows'], 'head'), '⛔ বেতনের খাত উইজেটের সারিতে');
        $this->assertSame(now()->format('Y-m'), $after['month']);
    }

    /**
     * ⭐ পয়সা পর্যন্ত, float নয় (a6-এর Mac দৌড়, ২০ অক্টোবর ২০২৬: MoneyIsNeverAFloatTest) — দুই খাত এক পয়সার তফাতে;
     * বড়টা আগে, আর মোট হুবহু দুইটার যোগফল (string, bcmath)।
     */
    public function test_heads_one_paisa_apart_sort_by_bcmath_and_sum_to_the_paisa(): void
    {
        $before = $this->widgets($this->owner)['expenses'];

        $this->postLines([StandardChart::RENT => ['debit' => '9000000.01']], 'test.rent_paisa');
        $this->postLines([StandardChart::ENTERTAINMENT => ['debit' => '9000000.02']], 'test.entertainment_paisa');

        $after = $this->widgets($this->owner)['expenses'];

        $this->assertSame('18000000.0300', bcsub($after['total'], $before['total'], 4), '⛔ মোট পয়সায় মেলে না');
        $heads = array_column($after['rows'], 'head');
        $this->assertSame([StandardChart::find(StandardChart::ENTERTAINMENT)->name(), StandardChart::find(StandardChart::RENT)->name()],
            array_slice($heads, 0, 2), '⛔ এক পয়সা বড় খাতটা আগে নয়');
    }

    public function test_profit_moves_with_a_sale_and_gross_is_sales_less_cost(): void
    {
        $before = $this->widgets($this->owner)['profit'];
        $this->sell(Customer::query()->orderBy('id')->firstOrFail(), '100');
        $after = $this->widgets($this->owner)['profit'];

        $this->assertSame(0, bccomp(bcsub($after['sales'], $before['sales'], 4), '200', 4), '⛔ বিক্রির ঘর বিলের মূল্যে বাড়েনি');
        $this->assertSame(1, bccomp($after['cogs'], $before['cogs'], 4), '⛔ বিক্রিত মালের দাম বাড়েনি');
        $this->assertSame(0, bccomp($after['gross'], bcsub($after['sales'], $after['cogs'], 4), 4), '⛔ মোট লাভ ≠ বিক্রি − মালের দাম');
    }

    public function test_a_customer_near_the_limit_is_named_with_point_and_branch_and_one_far_from_it_is_not(): void
    {
        [$near, $far] = Customer::query()->orderBy('id')->take(2)->get()->all();
        $near->forceFill(['credit_limit' => '210'])->save();
        $far->forceFill(['credit_limit' => '400'])->save();
        $this->sell($near, '100');
        $this->sell($far, '100');

        $widget = $this->widgets($this->owner)['credit_limits'];
        $names = array_column($widget['rows'], 'name');

        $near->load(['location.parent', 'branch']);
        $label = $near->nameWithPoint().($near->branch ? ' · '.$near->branch->name() : '');
        $this->assertContains($label, $names, '⛔ সীমার ৯৫%-এ থাকা গ্রাহক নেই, বা নাম · পয়েন্ট · শাখা নয়');
        $this->assertSame([], array_values(array_filter($widget['rows'], fn ($r) => $r['link'] === '/customers/'.$far->id)),
            '⛔ সীমার অর্ধেকে থাকা গ্রাহক ঝুঁকির তালিকায়');
        $row = collect($widget['rows'])->firstWhere('link', '/customers/'.$near->id);
        $this->assertGreaterThanOrEqual(90, $row['percent']);
        // ⓘ শতাংশ bcmath-এ ছাঁটা, float-এর floor নয় — বকেয়া ÷ সীমা, পূর্ণসংখ্যায় (210-এ 200 = 95.238… → 95)
        $this->assertSame((int) bcdiv(bcmul($row['due'], '100', 4), $row['limit'], 0), $row['percent']);
    }

    public function test_cheques_due_today_and_bounced_show_and_a_post_dated_one_does_not(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $due = $this->cheque($customer, now()->toDateString(), '2400', Cheque::PENDING);
        $this->cheque($customer, now()->addDay()->toDateString(), '9999', Cheque::PENDING);
        $bounced = $this->cheque($customer, now()->subDays(3)->toDateString(), '850', Cheque::BOUNCED);

        $widget = $this->widgets($this->owner)['cheques'];

        $this->assertSame(0, bccomp($widget['total'], '2400', 4), '⛔ আজকের জমার মোট ভুল — কাল-তারিখের চেক ঢুকেছে');
        $rows = collect($widget['rows'])->keyBy('link');
        $this->assertSame('due', $rows['/accounts/cheques/'.$due->id]['state'] ?? null);
        $this->assertSame('bounced', $rows['/accounts/cheques/'.$bounced->id]['state'] ?? null, '⛔ ফেরত চেক নেই');
        $customer->load(['location.parent', 'branch']);
        $this->assertSame($customer->nameWithPoint().($customer->branch ? ' · '.$customer->branch->name() : ''),
            $rows['/accounts/cheques/'.$due->id]['name']);
    }

    public function test_a_posted_bank_entry_is_one_more_row_to_reconcile(): void
    {
        $bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-WGT', 'name_en' => '1102-WGT', 'name_bn' => '1102-WGT',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK, 'is_active' => true, 'status' => 'confirmed',
        ]);
        $before = $this->widgets($this->owner)['bank_reconcile'];

        $voucher = app(\App\Modules\Accounts\Services\VoucherService::class)->create(
            ['type' => 'contra', 'trx_date' => now()->toDateString(), 'amount' => '500', 'narration' => 'নগদ ব্যাংকে'],
            app(\App\Modules\Accounts\Services\VoucherService::class)->twoLineEntry('contra',
                (int) Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->orderBy('id')->value('id'), $bank->id, '500'),
        );
        $this->putCashIn('500');
        app(\App\Modules\Accounts\Services\VoucherService::class)->post($voucher);

        // ⛔ দল-খাতের (১১০২ "ব্যাংক", নিজেও money_kind=bank) সারি গোনা হয় না — শিরোনামে টাকা বসে না (a6-এর দৌড়, MoneyNeverLandsOnAGroupAccountTest)
        $line = $voucher->lines()->where('account_id', $bank->id)->firstOrFail();
        $line->replicate()->forceFill(['account_id' => StandardChart::find(StandardChart::BANK)->id])->saveQuietly();

        $after = $this->widgets($this->owner)['bank_reconcile'];
        $this->assertSame($before['open'] + 1, $after['open'], '⛔ ব্যাংকের নতুন দাখিলা না-মেলানো সারিতে উঠল না');
        $this->assertSame('/accounts/reconciliations', $after['link']);
    }

    // ── যন্ত্রপাতি ──

    /** @return array<string, array<string, mixed>> */
    private function widgets(User $user): array
    {
        $out = $this->as($user)->getJson('/api/v1/widgets/summary')->assertOk()->json('widgets') ?? [];
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        return $out;
    }

    /** @param array<string, array{debit?: string, credit?: string}> $sides */
    private function postLines(array $sides, string $source): void
    {
        static $n = 900000;
        $lines = [];
        $total = '0';

        foreach ($sides as $code => $side) {
            $lines[] = ['account_id' => StandardChart::find($code)->id, 'debit' => $side['debit'] ?? '0', 'credit' => '0'];
            $total = bcadd($total, $side['debit'] ?? '0', 4);
        }

        $lines[] = ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => '0', 'credit' => $total];

        app(PostingEngine::class)->post(sourceType: $source, sourceId: ++$n, trxDate: now()->toDateString(), lines: $lines,
            documentNo: 'WGT-'.$n, branchId: $this->company->defaultBranch()?->id);
    }

    private function putCashIn(string $amount): void
    {
        $cash = (int) Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->orderBy('id')->value('id');
        app(PostingEngine::class)->post(sourceType: 'test.cash_in', sourceId: 1, trxDate: now()->toDateString(), lines: [
            ['account_id' => $cash, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => '0', 'credit' => $amount],
        ], documentNo: 'CASH-IN', branchId: $this->company->defaultBranch()?->id);
    }

    private function sell(Customer $customer, string $rate): void
    {
        $invoice = app(SalesInvoiceService::class)->create(
            ['customer_id' => $customer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->whereNull('tax_id')->orderBy('id')->value('id'), 'qty' => '2', 'rate' => $rate]],
        );
        app(SalesInvoiceService::class)->confirm($invoice);
    }

    private function cheque(Customer $customer, string $date, string $amount, string $status): Cheque
    {
        static $n = 0;

        return Cheque::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'CHQ-WGT-'.(++$n), 'direction' => Cheque::RECEIVED, 'cheque_date' => $date,
            'received_on' => now()->toDateString(), 'cheque_no' => (string) (5000 + $n), 'bank_name' => 'DBBL',
            'amount' => $amount, 'party_type' => 'customer', 'party_id' => $customer->id, 'status' => $status,
        ]);
    }

    /** @param list<string> $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        foreach ($keys as $key) {
            CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}
