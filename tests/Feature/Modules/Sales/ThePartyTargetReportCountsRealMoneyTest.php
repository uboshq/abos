<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Reports\PartyTargetReport;
use App\Modules\Sales\Services\CustomerTargetService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ পক্ষভিত্তিক লক্ষ্য বনাম আদায় — মালিক, ১৪ অক্টোবর ২০২৬ ([[PartyTargetReport]])।
 *
 * দাবি: আদায় = আসা আসল টাকা (আদায় + রসিদ; ফেরত মাল, জাবেদা আর ফেরত চেক বাদ) — লক্ষ্যের পাতার একই নিয়ম; মোট % = মোট আদায় ÷
 * মোট লক্ষ্য, গড় নয়; মালিকের চার মন্তব্য; ক্রমিক; শাখা, এলাকা আর বিক্রয়কর্মীর ছাঁকনি; Accounts আর Sales — দুই দরজা।
 */
final class ThePartyTargetReportCountsRealMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    /** ⓘ পরীক্ষার গ্রাহকদের নিজের শাখা — ডেমোর গ্রাহকদের আদায় যেন অঙ্কে না মেশে */
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-14 11:00:00'));

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        $this->branch = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'PTZ', 'name_en' => 'PT Test', 'is_active' => true]);
    }

    public function test_inflow_is_real_money_the_total_rate_is_total_over_total_and_each_remark_is_right(): void
    {
        $met = $this->dealer('PT-A', 'Aa Met');
        $behind = $this->dealer('PT-B', 'Bb Behind');
        $near = $this->dealer('PT-C', 'Cc Near');
        $none = $this->dealer('PT-D', 'Dd No Target');

        $this->target($met, '100000');
        $this->target($behind, '300000');
        $this->target($near, '100000');

        $this->credit($met, 'collection', '60000');
        $this->credit($met, 'receipt_voucher', '40000');
        // ⛔ টাকা নয় — ফেরত মাল, ছাড়ের ক্রেডিট নোট আর সমন্বয়ের জাবেদা খাতা কমায়, আদায় নয়
        $this->credit($behind, 'sales_return', '90000');
        $this->credit($behind, 'note', '20000');
        $this->credit($behind, 'journal_voucher', '50000');
        $this->credit($behind, 'receipt_voucher', '30000');
        // ⛔ ফেরত চেক — হাতে আসার দিন গোনা টাকা আবার বাদ
        $this->credit($behind, 'cheque', '25000');
        $this->bounce($behind, '25000');
        $this->credit($near, 'collection', '85000');
        $this->credit($none, 'collection', '5000');

        $rows = $this->rows();

        $this->assertSame(['PT-A', 'PT-B', 'PT-C', 'PT-D'], $rows->keys()->sort()->values()->all());
        $this->assertMoney('100000', $rows['PT-A']['inflow'], '⛔ আদায় আর রসিদ দুইটাই গোনা হয়নি');
        $this->assertMoney('30000', $rows['PT-B']['inflow'], '⛔ ফেরত মাল, ছাড়, জাবেদা বা ফেরত চেক আদায় হিসেবে গোনা হল');
        $this->assertMoney(app(CustomerTargetService::class)->achievedFor([(int) $behind->id], Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))[(int) $behind->id],
            $rows['PT-B']['inflow'], '⛔ রিপোর্ট লক্ষ্যের পাতার চেয়ে আলাদা অঙ্ক বলে');

        $this->assertSame(__('sales::party_target.met'), $rows['PT-A']['remark']);
        $this->assertSame(__('sales::party_target.behind'), $rows['PT-B']['remark']);
        $this->assertSame(__('sales::party_target.near'), $rows['PT-C']['remark']);
        $this->assertSame(__('sales::party_target.no_target'), $rows['PT-D']['remark']);
        $this->assertNull($rows['PT-D']['percent']);
        $this->assertMoney('10', $rows['PT-B']['percent'], '%');

        // ⓘ ক্রমিক নামের ক্রমে, ১ থেকে
        $this->assertSame([1, 2, 3, 4], $rows->sortBy('sl')->pluck('sl')->map(fn ($n) => (int) $n)->values()->all());
        $this->assertSame('PT-A', $rows->firstWhere('sl', 1)['code'] ?? null);

        // ⭐ মোট % = (১,০০,০০০ + ৩০,০০০ + ৮৫,০০০ + ৫,০০০) ÷ ৫,০০,০০০ = ৪৪.০ — গড় (১০০ + ১০ + ৮৫) ÷ ৩ = ৬৫ নয়
        $result = app(ReportEngine::class)->run(PartyTargetReport::KEY, ['from' => '2026-10-01', 'to' => '2026-10-31', 'branch_id' => $this->branch->id]);
        $said = (app(ReportEngine::class)->get(PartyTargetReport::KEY)->summary)($result->totals);
        $this->assertSame('44.0', $said['value'], '⛔ মোট % মোট ÷ মোট নয়');
    }

    public function test_branch_location_and_salesperson_narrow_the_rows(): void
    {
        $here = $this->dealer('PT-H', 'Here Dealer');
        $there = $this->dealer('PT-T', 'There Dealer');
        $there->forceFill(['branch_id' => $this->company->defaultBranch()?->id])->save();

        $point = Location::query()->whereNull('parent_id')->orderBy('id')->firstOrFail();
        $here->forceFill(['location_id' => $point->id])->save();

        $seller = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        DB::table('dealer_bindings')->insert(['public_id' => (string) Str::uuid7(), 'company_id' => $this->company->id,
            'user_id' => $seller->id, 'customer_id' => $there->id, 'starts_on' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);

        foreach ([$here, $there] as $c) {
            $this->target($c, '1000');
            $this->credit($c, 'collection', '500');
        }

        $this->assertSame(['PT-T'], $this->rows(['branch_id' => $this->company->defaultBranch()?->id])->keys()->intersect(['PT-H', 'PT-T'])->values()->all(),
            '⛔ শাখার ছাঁকনিতে অন্য শাখার গ্রাহক এল');
        $this->assertSame(['PT-H'], $this->rows(['location_id' => $point->id, 'branch_id' => null])->keys()->intersect(['PT-H', 'PT-T'])->values()->all(),
            '⛔ এলাকার ছাঁকনি কাজ করেনি');
        $this->assertSame(['PT-T'], $this->rows(['salesman_id' => $seller->id, 'branch_id' => null])->keys()->intersect(['PT-H', 'PT-T'])->values()->all(),
            '⛔ বিক্রয়কর্মীর ছাঁকনিতে অন্যের ডিলার এল');
    }

    /**
     * ⭐ এলাকা ধরে — মালিক, ১৪ অক্টোবর ২০২৬: "report area wise jug thakbe"। দাবি: প্রতিটা এলাকার উপ-মোট, Σ উপ-মোট = সর্বমোট,
     * উপ-মোট আর সর্বমোটের % = আদায় ÷ লক্ষ্য (গড় নয়); এলাকা-সারাংশ রূপের অঙ্ক গ্রাহক-রূপের হুবহু।
     */
    public function test_area_sections_add_up_to_the_grand_total_and_the_area_summary_agrees(): void
    {
        $north = $this->place('PTA-N', 'North Area', Location::AREA);
        $south = $this->place('PTA-S', 'South Area', Location::AREA);
        $n1 = $this->place('PTP-N1', 'North Point', Location::POINT, $north);
        $s1 = $this->place('PTP-S1', 'South Point', Location::POINT, $south);

        $a = $this->dealer('PT-N1', 'North One');
        $b = $this->dealer('PT-N2', 'North Two');
        $c = $this->dealer('PT-S1', 'South One');
        $a->forceFill(['location_id' => $n1->id])->save();
        $b->forceFill(['location_id' => $n1->id])->save();
        $c->forceFill(['location_id' => $s1->id])->save();

        // উত্তর: আদায় ১,৫০,০০০ / লক্ষ্য ২,০০,০০০ = ৭৫%; দক্ষিণ: ১০,০০০ / ১,০০,০০০ = ১০% — গড় ৪২.৫, আসল মোট ১,৬০,০০০ ÷ ৩,০০,০০০ = ৫৩.৩৩
        $this->target($a, '100000');
        $this->target($b, '100000');
        $this->target($c, '100000');
        $this->credit($a, 'collection', '100000');
        $this->credit($b, 'receipt_voucher', '50000');
        $this->credit($c, 'collection', '10000');

        $result = app(ReportEngine::class)->run(PartyTargetReport::KEY, ['from' => '2026-10-01', 'to' => '2026-10-31',
            'branch_id' => $this->branch->id], perPage: 1000, byBranch: true);

        $sections = collect($result->sections)->keyBy('branchName');
        $this->assertTrue($sections->has('North Area') && $sections->has('South Area'), '⛔ এলাকার ভাগ নেই: '.$sections->keys()->implode(', '));
        $this->assertMoney('150000', $sections['North Area']->totals['inflow'], 'উত্তরের উপ-মোট আদায়');
        $this->assertMoney('75', $sections['North Area']->totals['percent'], '⛔ উপ-মোটের % আদায় ÷ লক্ষ্য নয়');
        $this->assertMoney('10', $sections['South Area']->totals['percent'], 'দক্ষিণের উপ-মোটের %');

        $sumIn = $sections->reduce(fn (string $s, $sec) => bcadd($s, (string) $sec->totals['inflow'], 4), '0');
        $sumTg = $sections->reduce(fn (string $s, $sec) => bcadd($s, (string) $sec->totals['target'], 4), '0');
        $this->assertMoney($result->totals['inflow'], $sumIn, '⛔ এলাকার উপ-মোটের যোগ সর্বমোট আদায় নয়');
        $this->assertMoney($result->totals['target'], $sumTg, '⛔ এলাকার উপ-মোটের যোগ সর্বমোট লক্ষ্য নয়');
        $this->assertMoney(bcdiv(bcmul($result->totals['inflow'], '100', 6), $result->totals['target'], 2), $result->totals['percent'],
            '⛔ সর্বমোটের % মোট ÷ মোট নয়');

        // ⓘ এলাকা-সারাংশ — এক এলাকা এক সারি, অঙ্ক উপরের ভাগের হুবহু
        $areas = collect(app(ReportEngine::class)->run(PartyTargetReport::AREAS, ['from' => '2026-10-01', 'to' => '2026-10-31', 'branch_id' => $this->branch->id], perPage: 1000)->rows)
            ->map(fn ($r) => (array) $r)->keyBy('area');
        $this->assertSame(2, (int) $areas['North Area']['customers']);
        $this->assertMoney('150000', $areas['North Area']['inflow'], '⛔ এলাকা-সারাংশ আর গ্রাহক-রূপ আলাদা অঙ্ক বলে');
        $this->assertMoney('200000', $areas['North Area']['target'], 'এলাকা-সারাংশের লক্ষ্য');
        $this->assertSame(__('sales::party_target.behind'), $areas['North Area']['remark']);
    }

    /**
     * ⭐ Sales ড্যাশবোর্ডেও — মালিক, ১৪ অক্টোবর ২০২৬: "eta sales er dashboard eo daw"। দাবি: ঘরের মোট আদায়, মোট লক্ষ্য আর % রিপোর্টের
     * চলতি মাসের সর্বমোটের হুবহু; পিছিয়ে থাকা তালিকার প্রথমজন সবচেয়ে কম %; পাতা খোলে।
     */
    public function test_the_dashboard_tile_is_the_reports_own_month_and_the_lagging_list_starts_lowest(): void
    {
        $low = $this->dealer('PT-L', 'Lowest Dealer');
        $mid = $this->dealer('PT-M', 'Middle Dealer');
        $this->target($low, '500000');
        $this->target($mid, '100000');
        $this->credit($low, 'collection', '1000');
        $this->credit($mid, 'collection', '60000');

        $tile = PartyTargetReport::thisMonth();
        $report = app(ReportEngine::class)->run(PartyTargetReport::KEY, ['from' => '2026-10-01', 'to' => '2026-10-14'], perPage: 1000);

        $this->assertMoney($report->totals['inflow'], $tile['inflow'], '⛔ ড্যাশবোর্ডের আদায় রিপোর্টের সর্বমোট নয়');
        $this->assertMoney($report->totals['target'], $tile['target'], '⛔ ড্যাশবোর্ডের লক্ষ্য রিপোর্টের সর্বমোট নয়');

        $lowest = collect($report->rows)->map(fn ($r) => (array) $r)->filter(fn (array $r) => bccomp((string) $r['target'], '0', 4) > 0)
            ->sortBy(fn (array $r) => (float) $r['inflow'] / (float) $r['target'])->first();
        $this->assertSame((int) $lowest['source_id'], (int) $tile['lagging']->first()->source_id, '⛔ সবচেয়ে পিছিয়ে থাকা জন তালিকার মাথায় নেই');
        $this->assertLessThanOrEqual(5, $tile['lagging']->count());

        $this->get(route('module.dashboard', ['module' => 'sales']))->assertOk()->assertSee(__('sales::party_target.tile'))->assertSee(__('sales::party_target.lagging'));
    }

    public function test_both_doors_open_the_report_for_their_own_key(): void
    {
        $this->target($this->dealer('PT-X', 'Door Dealer'), '1000');

        $accountant = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $accountant->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->company->id, fn () => $accountant->givePermissionTo(Permission::findOrCreate('accounts.report', 'web')));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($accountant->fresh())->get(route('accounts.report.show', ['slug' => 'party-target']))
            ->assertOk()->assertSee('Door Dealer');
        $this->actingAs($this->owner)->get(route('sales.report.show', ['slug' => 'party-target']))
            ->assertOk()->assertSee('Door Dealer');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $f */
    private function rows(array $f = []): Collection
    {
        $f = array_filter($f + ['branch_id' => $this->branch->id], fn ($v) => $v !== null);

        return collect(app(ReportEngine::class)->run(PartyTargetReport::KEY, ['from' => '2026-10-01', 'to' => '2026-10-31'] + $f, perPage: 1000)->rows)
            ->map(fn ($r) => (array) $r)
            ->keyBy('code');
    }

    private function dealer(string $code, string $name): Customer
    {
        return Customer::query()->create(['code' => $code, 'name_en' => $name, 'name_bn' => $name, 'is_active' => true,
            'branch_id' => $this->branch->id]);
    }

    private function place(string $code, string $name, string $level, ?Location $parent = null): Location
    {
        return Location::query()->create(['company_id' => $this->company->id, 'parent_id' => $parent?->id, 'code' => $code,
            'name_en' => $name, 'name_bn' => $name, 'level' => $level, 'is_active' => true]);
    }

    private function target(Customer $dealer, string $amount): void
    {
        app(CustomerTargetService::class)->setOne((int) $dealer->id, Carbon::parse('2026-10-01'), $amount, '2026-10-31');
    }

    private function credit(Customer $dealer, string $source, string $amount): void
    {
        $cash = DB::table('accounts')->where('company_id', $this->company->id)->where('money_kind', 'cash')->where('is_group', false)->orderBy('id')->value('id');

        app(PostingEngine::class)->post(
            sourceType: $source, sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: [
                ['account_id' => $cash, 'debit' => $amount],
                ['account_id' => (int) StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => $amount, 'party_type' => 'customer', 'party_id' => $dealer->id],
            ],
            branchId: $this->company->defaultBranch()?->id,
        );
    }

    /** চেক ফেরত — খাতায় গ্রাহকের ডেবিট, `cheque:bounced` নামে */
    private function bounce(Customer $dealer, string $amount): void
    {
        app(PostingEngine::class)->post(
            sourceType: 'cheque:bounced', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: [
                ['account_id' => (int) StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => $amount, 'party_type' => 'customer', 'party_id' => $dealer->id],
                ['account_id' => (int) StandardChart::find(StandardChart::CHEQUES_IN_HAND)->id, 'credit' => $amount],
            ],
            branchId: $this->company->defaultBranch()?->id,
        );
    }

    private function assertMoney(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, (string) ($actual ?? '0'), 2), "{$message}: চাই {$expected}, এল ".var_export($actual, true));
    }
}
