<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\Trend;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Services\Alerts;
use App\Modules\Executive\Services\Board;
use App\Modules\Executive\Services\Comparison;
use App\Modules\Executive\Services\Figures;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * তুলনা আর সতর্কতা — দুইটাই মডিউলের নিজের সংখ্যা আর রিপোর্টের নিজের সারি।
 *
 * ⭐ তুলনার "এখন" আর "আজ" পাতার সংখ্যা এক; সতর্কতার সংখ্যা আর চাপলে-খোলা রিপোর্টের সারি এক।
 */
final class TheOwnerComparesAndIsWarnedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->beta = Company::query()->where('code', 'FMART')->firstOrFail();

        $this->actingAs($this->owner);
        CompanyContext::set((int) $this->alpha->id, (int) $this->owner->current_branch_id);
    }

    public function test_the_compare_page_says_what_the_today_page_says(): void
    {
        $this->sell('3', '1500');

        $compare = app(Comparison::class)->build($this->owner, Comparison::COMPANIES, null, Trend::PREVIOUS_MONTH);
        $board = app(Board::class)->build($this->owner, Figures::MONTH);

        $this->assertSame(array_column($board['companies'], 'id'), array_column($compare['rows'], 'id'));

        foreach ($compare['rows'] as $i => $row) {
            foreach ([Figures::SALES, Figures::COLLECTIONS, Figures::RECEIVABLE, Figures::PROFIT] as $key) {
                $this->assertSame($board['companies'][$i]['values'][$key], $row['now'][$key],
                    "⛔ {$row['name']}-এর '{$key}' তুলনার পাতায় এক, আজ পাতায় আরেক।");
            }
        }

        $this->assertSame(1, bccomp($compare['rows'][0]['now'][Figures::SALES], '0', 4), 'প্রস্তুতিটাই ভুল — বিক্রি শূন্য।');
        $this->assertSame(CompanyContext::id(), (int) $this->alpha->id, '⛔ তুলনার পর প্রসঙ্গটা ফেরেনি।');
    }

    public function test_then_is_the_same_days_of_the_previous_month_and_change_is_honest(): void
    {
        Carbon::setTestNow('2026-10-09 10:00:00');

        $compare = app(Comparison::class)->build($this->owner, Comparison::COMPANIES, null, Trend::PREVIOUS_MONTH);

        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-09'], $compare['now']);
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-09'], $compare['was'],
            '⛔ "আগের মাস" মানে আগের মাসের একই দিনগুলো — ঠিক ততদিন আগের দিনগুলো নয়।');

        $lastYear = app(Comparison::class)->build($this->owner, Comparison::COMPANIES, null, ReportEngine::COMPARE_LAST_YEAR);
        $this->assertSame(['from' => '2025-10-01', 'to' => '2025-10-09'], $lastYear['was']);

        // ⚠️ মাসের শেষ পেরোয় না
        $this->assertSame(['from' => '2026-02-01', 'to' => '2026-02-28'], Trend::previous(Trend::PREVIOUS_MONTH, '2026-03-01', '2026-03-31'));

        // ⓘ আগেরটা শূন্য হলে বদলের শতাংশ নেই — "নতুন" আর "১০০% বেড়েছে" এক কথা নয়
        foreach ($compare['rows'] as $row) {
            if ($row['was'][Figures::SALES] !== null && bccomp($row['was'][Figures::SALES], '0', 4) === 0) {
                $this->assertNull($row['change'][Figures::SALES]);
            }
        }

        Carbon::setTestNow();
    }

    public function test_branch_against_branch_stays_inside_the_users_reach(): void
    {
        $accountant = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->grantTheKey($accountant);
        $mms = Branch::acrossAllCompanies()->where('company_id', $this->alpha->id)->where('code', 'MMS')->firstOrFail();

        UserDataScope::query()->create([
            'company_id' => $this->alpha->id, 'user_id' => $accountant->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mms->id,
        ]);
        app(DataScope::class)->forget();

        $this->actingAs($accountant->fresh());
        $compare = app(Comparison::class)->build($accountant->fresh(), Comparison::BRANCHES, (int) $this->beta->id, Trend::PREVIOUS_MONTH);

        // ⛔ অন্যের কোম্পানি চাইলেও নিজেরটাই — আর তার ভিতরে কেবল নিজের শাখা
        $this->assertSame((int) $this->alpha->id, $compare['company']);
        $this->assertSame([(int) $mms->id], array_column($compare['rows'], 'id'));
        $this->assertSame([(int) $this->alpha->id], array_column($compare['companies'], 'id'));

        $this->get(route('executive.compare', ['mode' => 'branches']))->assertOk()
            ->assertDontSee($this->beta->name_bn)->assertDontSee($this->beta->name_en);
    }

    public function test_the_trend_adds_up_to_the_period(): void
    {
        $this->sell('2', '1000');
        $from = Carbon::today()->startOfMonth()->subMonths(2)->toDateString();
        $to = Carbon::today()->toDateString();

        $places = [['company_id' => (int) $this->alpha->id, 'branch_id' => null], ['company_id' => (int) $this->beta->id, 'branch_id' => null]];

        foreach ([Trend::DAILY, Trend::WEEKLY, Trend::MONTHLY] as $grain) {
            $series = app(Comparison::class)->trend($this->owner, $places, Figures::SALES, $grain, $from, $to);
            $sum = array_reduce($series, fn (string $s, array $b) => bcadd($s, $b['value'], 4), '0');

            $whole = '0';
            foreach ($places as $place) {
                $whole = bcadd($whole, CompanyContext::forCompany($place['company_id'],
                    fn () => SalesMetrics::invoiceTotal($from, $to)), 4);
            }

            $this->assertSame(0, bccomp($sum, $whole, 4), "⛔ '{$grain}' ধারার খোপগুলোর যোগ {$sum}, অথচ গোটা পরিসরের বিক্রি {$whole}।");
        }

        $this->get(route('executive.compare', ['grain' => 'weekly', 'figure' => 'profit']))->assertOk();
    }

    public function test_each_alert_counts_exactly_the_rows_its_report_opens_with(): void
    {
        // ⓘ ব্যর্থ লগইন — আমাদের কোম্পানির লোকের নামে তিনটা, অন্য কোম্পানির নামে একটা
        foreach ([1, 2, 3] as $n) {
            LoginAttempt::query()->create(['company_id' => $this->alpha->id, 'identifier' => 'accounts@abos.test', 'succeeded' => false, 'reason' => 'password']);
        }
        LoginAttempt::query()->create(['company_id' => $this->beta->id, 'identifier' => 'owner@abos.test', 'succeeded' => false, 'reason' => 'password']);

        $companies = [['id' => (int) $this->alpha->id, 'name' => 'A', 'only' => null], ['id' => (int) $this->beta->id, 'name' => 'B', 'only' => null]];
        $alerts = collect(app(Alerts::class)->all($this->owner, $companies))->keyBy('kind');

        $failed = collect($alerts[Alerts::FAILED_LOGIN]['companies'])->keyBy('id');
        $this->assertSame(3, $failed[(int) $this->alpha->id]['count'], '⛔ ব্যর্থ লগইন ঠিকমতো গোনা হয়নি।');
        $this->assertSame(1, $failed[(int) $this->beta->id]['count'], '⛔ অন্য কোম্পানির ব্যর্থ লগইন এই কোম্পানিতে গোনা হয়েছে।');

        // ⭐ অচল মাল: সংখ্যাটা = ঐ রিপোর্ট, ঐ ছাঁকনিতে, যত সারি দেখায়
        $dead = collect($alerts[Alerts::STOCK_DEAD]['companies'])->keyBy('id')[(int) $this->alpha->id];
        [$route, $params] = Alerts::target(Alerts::STOCK_DEAD);
        $this->assertSame([$route, $params], [$dead['route'], $dead['params']]);

        $rows = CompanyContext::forCompany((int) $this->alpha->id,
            fn () => app(ReportEngine::class)->run('inventory.slow_dead', ['status' => 'dead'], 1, 5000)->rows);
        $this->assertSame(count($rows), $dead['count']);
        $this->assertGreaterThan(0, $dead['count'], 'প্রস্তুতিটাই ভুল — ডেমোতে অচল মাল নেই, তাই দাবিটা কিছুই মাপে না।');

        // ⓘ চাপলে যে পাতা খোলে সেটা সত্যিই খোলে
        $this->get(route($route, $params))->assertOk();
    }

    public function test_a_company_without_the_report_key_reads_not_allowed_not_zero(): void
    {
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        CompanyContext::forCompany((int) $this->alpha->id, fn () => Role::findByName('salesman')->givePermissionTo('executive.view'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($salesman->fresh());
        $alerts = collect(app(Alerts::class)->all($salesman->fresh(), [['id' => (int) $this->alpha->id, 'name' => 'A', 'only' => null]]))->keyBy('kind');

        $this->assertNull($alerts[Alerts::BACKDATED]['companies'][0]['count'],
            '⛔ নিরীক্ষার চাবি ছাড়া পিছনের তারিখের সংখ্যা "০" দেখাচ্ছে — শূন্য মানে "সব ঠিক আছে"।');

        $this->get(route('executive.alerts'))->assertOk()->assertSee(__('executive::alert.no_key'));
    }

    public function test_the_alerts_page_never_names_another_persons_company(): void
    {
        $accountant = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->grantTheKey($accountant);

        $this->actingAs($accountant->fresh())->get(route('executive.alerts'))
            ->assertOk()
            ->assertSee($this->alpha->name())
            ->assertDontSee($this->beta->name_bn)
            ->assertDontSee($this->beta->name_en);
    }

    private function sell(string $qty, string $rate): void
    {
        CompanyContext::forCompany((int) $this->alpha->id, function () use ($qty, $rate) {
            $warehouse = Warehouse::query()->where('code', 'WH-MMS')->firstOrFail();
            CompanyContext::set((int) $this->alpha->id, (int) $warehouse->branch_id);

            app(DirectSaleService::class)->complete(
                ['customer_id' => Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
                    'warehouse_id' => $warehouse->id, 'own_transport' => '1', DirectSaleService::REPEAT_FIELD => '1'],
                [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id,
                    'qty' => $qty, 'rate' => $rate, 'free_qty' => '0']],
            );
        });

        app(Board::class)->refresh($this->owner);
    }

    private function grantTheKey(User $user): void
    {
        CompanyContext::forCompany((int) $this->alpha->id, function () use ($user) {
            foreach ($user->roles as $role) {
                Role::findById($role->id)->givePermissionTo('executive.view');
            }
        });
        $user->unsetRelation('roles')->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
