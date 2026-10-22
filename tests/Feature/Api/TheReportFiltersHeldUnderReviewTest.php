<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ ফোনের রিপোর্ট-ছাঁকনি, a5-এর পর্যালোচনার পরে — ১৭ অক্টোবর ২০২৬ (fe-র ছয় কাজ)।
 *
 * ⛔ যা ধরা পড়েছিল:
 *   ১ পাতার মোটে শতাংশ যোগ হত — তিনটা ৫০%-এর সারির মোট "১৫০%"
 *   ২ বাছাই থেকে নম্বর বের করা হত ২০০০ সারির তালিকা থেকে — তার পরের লট দেয়ালের ভিতরে থেকেও ফেরত
 *   ৩ মাসওয়ারি নগদে ফোন সব খাত দেখাত, ওয়েব কেবল টাকার খাত
 *   ৪ একই লেখার দুইটা ঋণ (নম্বরহীন, একই ব্যাংক) একটার খাতায় গিয়ে পড়ত, ভুলের কথা ছাড়াই
 *   ৫ ডিফল্ট সময়কাল বদলালে আগের সূচির মেইল নীরবে বদলাত
 *   ৬ সীমিত কর্মী, অন্য কোম্পানির মান আর তালিকা-আকারের ইনপুটের দাবি ছিল না
 */
final class TheReportFiltersHeldUnderReviewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function phone(string $url, ?User $as = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(($as ?? $this->owner())->fresh(), [AuthController::APP]);

        return $this->get($url, ['Accept' => 'application/json']);
    }

    /** @return array<string, array<string, mixed>> */
    private function choices(string $key, ?User $as = null): array
    {
        return collect($this->phone('/api/v1/reports/'.$key.'/choices', $as)->assertOk()->json('filters'))->keyBy('key')->all();
    }

    // ── ১ ─────────────────────────────────────────────────────────────

    public function test_a_percent_column_totals_by_its_own_ratio_not_by_adding_percents(): void
    {
        app(ReportEngine::class)->register(new ReportDefinition(
            key: 'test.ratio_page',
            title: 'Ratio',
            query: fn (array $f) => DB::query()->fromSub(
                DB::query()->selectRaw("'A' as name, 50 as inflow, 100 as target")
                    ->unionAll(DB::query()->selectRaw("'B' as name, 50 as inflow, 100 as target"))
                    ->unionAll(DB::query()->selectRaw("'C' as name, 100 as inflow, 400 as target")),
                'x')->orderBy('name'),
            columns: [
                ['key' => 'name', 'label' => 'Name', 'type' => ReportColumn::TEXT],
                ['key' => 'inflow', 'label' => 'Inflow', 'type' => ReportColumn::MONEY],
                ['key' => 'target', 'label' => 'Target', 'type' => ReportColumn::MONEY],
                ['key' => 'percent', 'label' => 'Percent', 'type' => ReportColumn::PERCENT, 'ratio' => ['inflow', 'target']],
            ],
            filters: [],
            permission: 'accounts.report',
        ));

        // ⓘ পাতায় প্রথম দুই সারি — প্রতিটা ৫০%; পাতার মোট ১০০ ÷ ২০০ = ৫০%, যোগ করলে ১০০% আসত
        $two = $this->phone('/api/v1/reports/test.ratio_page?perPage=2')->assertOk();
        $this->assertSame(50.0, (float) $two->json('pageTotals.percent'), '⛔ পাতার মোটে শতাংশ যোগ হয়েছে');
        $this->assertSame(100.0, (float) $two->json('pageTotals.inflow'));

        // ⓘ সর্বমোট ২০০ ÷ ৬০০ = ৩৩.৩৩% — পাতার মোট সর্বমোটের একই নিয়মে, এক পাতায় সব হলে দুইটা হুবহু এক
        $all = $this->phone('/api/v1/reports/test.ratio_page')->assertOk();
        $this->assertSame($all->json('totals.percent'), $all->json('pageTotals.percent'));
        $this->assertEqualsWithDelta(33.33, (float) $all->json('totals.percent'), 0.01);
    }

    // ── ২ ─────────────────────────────────────────────────────────────

    public function test_a_lot_past_the_list_ceiling_is_still_found_inside_the_walls(): void
    {
        $product = Product::query()->orderBy('id')->firstOrFail();
        $rows = [];
        for ($i = 1; $i <= 2001; $i++) {
            $rows[] = ['public_id' => (string) Str::uuid7(), 'company_id' => $this->company->id, 'branch_id' => null,
                'product_id' => $product->id, 'batch_no' => sprintf('LOT-%05d', $i), 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('inv_batches')->insert($chunk);
        }
        $last = DB::table('inv_batches')->where('batch_no', 'LOT-02001')->first();

        $lot = $this->choices('inventory.lot_trace')['batch_id'];
        $this->assertTrue($lot['capped'], 'ছক ভুল: তালিকা ছাদ ছাড়ায়নি');
        $this->assertNotContains((string) $last->public_id, collect($lot['options'])->pluck('value')->all(), 'ছক ভুল: শেষ লট তালিকাতেই আছে');

        // ⭐ ছাদের বাইরের লট — public_id আর লেখা দুইভাবেই চলে
        $this->phone('/api/v1/reports/inventory.lot_trace?batch_id='.$last->public_id)->assertOk();
        $this->phone('/api/v1/reports/inventory.lot_trace?'.http_build_query(['batch_id' => 'LOT-02001']))->assertOk();

        // ⭐ পর্দার খোঁজা ছাদের বাইরেও পায়
        $found = $this->phone('/api/v1/reports/inventory.lot_trace/choices/batch_id?q=LOT-02001')->assertOk()->json('options');
        $this->assertSame([(string) $last->public_id], collect($found)->pluck('value')->all());
    }

    // ── ৩ ─────────────────────────────────────────────────────────────

    public function test_monthly_cash_offers_the_same_accounts_on_the_phone_as_on_the_web_page(): void
    {
        $this->actingAs($this->owner());
        $web = $this->get(route('accounts.report.show', 'monthly-cash'))->assertOk()->viewData('accounts')
            ->map(fn ($a) => (string) $a->public_id)->sort()->values()->all();

        $phone = collect($this->choices('accounts.monthly_cash')['account_id']['options'])->pluck('value')->sort()->values()->all();

        $this->assertNotEmpty($web, 'ছক ভুল: কোনো টাকার খাত নেই');
        $this->assertSame($web, $phone, '⛔ মাসওয়ারি নগদে ফোন আর ওয়েব আলাদা খাত দেখায়');
    }

    // ── ৪ ─────────────────────────────────────────────────────────────

    public function test_two_loans_that_read_the_same_never_open_each_others_ledger(): void
    {
        $loan = fn () => BankFacility::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => null,
            'kind' => BankFacility::TERM, 'bank' => 'Pubali Bank', 'sanctioned_on' => '2026-07-01', 'limit_amount' => '1000000',
            'interest_rate' => '11', 'opening_drawn' => '0', 'liability_account_id' => StandardChart::find('2210')->id,
            'status' => DocumentStatus::CONFIRMED,
        ]);
        $first = $loan();
        $second = $loan();

        $options = collect($this->choices('finance.bank_loan_ledger')['facility_id']['options'])->where('label', 'Pubali Bank');
        $this->assertCount(2, $options, 'ছক ভুল: দুইটা একই লেখার ঋণ নেই');
        $this->assertSame(
            [(string) $first->public_id, (string) $second->public_id],
            $options->pluck('value')->sort()->values()->all(),
            '⛔ মান লেখা — দুইটা ঋণ এক হয়ে গেছে');

        $this->phone('/api/v1/reports/finance.bank_loan_ledger?facility_id='.$second->public_id)->assertOk();
        $this->phone('/api/v1/reports/finance.bank_loan_ledger?'.http_build_query(['facility_id' => 'Pubali Bank']))
            ->assertStatus(422)->assertJsonValidationErrors('facility_id');
    }

    // ── ৫ ─────────────────────────────────────────────────────────────

    public function test_a_schedule_keeps_the_period_it_was_saved_with(): void
    {
        $old = ReportSchedule::query()->create([
            'company_id' => $this->company->id, 'report_key' => 'sales.monthly', 'filters' => [], 'format' => 'csv',
            'frequency' => 'monthly', 'at_time' => '07:00', 'day_of_month' => 1, 'recipients' => [], 'created_by' => $this->owner()->id,
            'is_active' => true,
        ]);
        $pinned = ReportSchedule::query()->create([
            'company_id' => $this->company->id, 'report_key' => 'sales.monthly', 'filters' => ['from' => '2026-05-05'], 'format' => 'csv',
            'frequency' => 'monthly', 'at_time' => '07:00', 'day_of_month' => 1, 'recipients' => [], 'created_by' => $this->owner()->id,
            'is_active' => true,
        ]);

        (require database_path('migrations/2027_02_26_100000_a_mailed_report_kept_the_period_it_was_saved_with.php'))->up();

        $this->assertSame('month', $old->fresh()->filters[ReportDefinition::SCHEDULE_PERIOD] ?? null, '⛔ আগের সূচির সময়কাল লেখা হয়নি');
        $this->assertSame(now()->startOfMonth()->toDateString(), ScheduledReportRunner::filtersOf($old->fresh())['from'],
            '⛔ আগের সূচি এখন বছর-থেকে-আজ পাঠাবে');
        $this->assertArrayNotHasKey(ReportDefinition::SCHEDULE_PERIOD, ScheduledReportRunner::filtersOf($old->fresh()), 'লুকানো চাবি ইঞ্জিনে গেছে');
        $this->assertSame('2026-05-05', ScheduledReportRunner::filtersOf($pinned->fresh())['from'], 'দেওয়া শুরুর তারিখ বদলেছে');

        // ⓘ নতুন সূচি সংরক্ষণের মুহূর্তের ডিফল্ট নিয়ে বসে
        $this->actingAs($this->owner());
        $this->post(route('system_admin.reports.schedule.store'), [
            'report_key' => 'sales.monthly', 'filters' => [], 'format' => 'csv', 'frequency' => 'daily', 'at_time' => '07:00',
        ])->assertRedirect();
        $new = ReportSchedule::query()->latest('id')->firstOrFail();
        $this->assertSame(ReportDefinition::PERIOD_FISCAL_YEAR, $new->filters[ReportDefinition::SCHEDULE_PERIOD] ?? null);
    }

    // ── ৬ ─────────────────────────────────────────────────────────────

    public function test_a_branch_clerk_sees_only_their_branch_and_cannot_ask_for_another(): void
    {
        $branches = Branch::query()->where('company_id', $this->company->id)->orderBy('id')->get();
        $this->assertGreaterThan(1, $branches->count(), 'ছক ভুল: দুইটা শাখা লাগে');
        [$mine, $other] = [$branches[0], $branches[1]];

        Permission::findOrCreate('inventory.report', 'web');
        // ⓘ মজুদের কেরানি মজুদ দেখেন — চাবি ছাড়া ফোনে মজুদের রিপোর্টই খোলে না ([[ThePhoneReportListShowedStockToASalesmanTest]]);
        // এই দাবির বিষয় শাখার দেয়াল
        Permission::findOrCreate('inventory.stock.view', 'web');
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->givePermissionTo('inventory.report', 'inventory.stock.view');
        UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mine->id]);

        $offered = collect($this->choices('inventory.stock_summary', $clerk)['branch']['options'])->pluck('value')->all();
        $this->assertSame([(string) $mine->public_id], $offered, '⛔ সীমিত কর্মী অন্য শাখা দেখেন');

        $this->phone('/api/v1/reports/inventory.stock_summary?branch='.$other->public_id, $clerk)
            ->assertStatus(422)->assertJsonValidationErrors('branch');

        // ⓘ চাবি নেই এমন রিপোর্ট — ৪০৩, বাছাই-তালিকাও নয়
        $this->phone('/api/v1/reports/accounts.ledger/choices', $clerk)->assertForbidden();
    }

    public function test_another_companys_values_and_list_shaped_input_are_refused_not_crashed(): void
    {
        $mart = Company::query()->where('code', 'FMART')->firstOrFail();
        // ⓘ অন্য কোম্পানির গুদাম নিজেরা বসানো — ডেমোর উপর ছেড়ে দেওয়া নয়
        DB::table('inv_warehouses')->insert(['public_id' => (string) Str::uuid7(), 'company_id' => $mart->id, 'code' => 'WH-MART',
            'name_en' => 'Mart store', 'is_default' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $foreignWarehouse = Warehouse::query()->withoutGlobalScopes()->where('company_id', $mart->id)->where('code', 'WH-MART')->firstOrFail();
        $foreignAccount = DB::table('accounts')->where('company_id', $mart->id)->first();

        $this->phone('/api/v1/reports/inventory.stock_ledger?warehouse_id='.$foreignWarehouse->public_id)
            ->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
        $this->phone('/api/v1/reports/inventory.stock_ledger?warehouse_id='.$foreignWarehouse->id)
            ->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
        if ($foreignAccount !== null) {
            $this->phone('/api/v1/reports/accounts.ledger?account_id='.$foreignAccount->public_id)
                ->assertStatus(422)->assertJsonValidationErrors('account_id');
        }

        foreach ([
            '/api/v1/reports/inventory.stock_summary?branch[]=x' => 'branch',
            '/api/v1/reports/inventory.stock_ledger?warehouse_id[]=1' => 'warehouse_id',
            '/api/v1/reports/inventory.slow_dead?status[]=dead' => 'status',
            '/api/v1/reports/accounts.ledger?account_id[]=1' => 'account_id',
        ] as $url => $field) {
            $this->phone($url)->assertStatus(422)->assertJsonValidationErrors($field);
        }
    }
}
