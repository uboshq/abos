<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Integrity\FixedAssetChecks;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AssetCategory;
use App\Modules\Accounts\Models\AssetTaxYear;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Reports\FixedAssetReports;
use App\Modules\Accounts\Services\AssetCategoryService;
use App\Modules\Accounts\Services\AssetEventService;
use App\Modules\Accounts\Services\AssetTaxService;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * সম্পদের পাতা আর হিসাবের খাতা একই গল্প বলে — স্থায়ী সম্পদ ধাপ ৫ (মালিক, ১০ অক্টোবর ২০২৬; IAS 16.73)।
 *
 * ⭐ দাবিগুলো:
 *   · চলাচলের তফসিল নিজে মেলে: শুরু + সংযোজন + পুনর্মূল্যায়ন − বিদায় = শেষ, সঞ্চিত ক্ষয়েরও; শেষটা নিবন্ধনের সমান।
 *   · নিবন্ধনের যোগফল = সম্পদ আর সঞ্চিত ক্ষয়ের খাতের জের; সম্পদের খাতে হাতে জাবেদা বসালে যাচাই ধরে।
 *   · করের অবচয় শ্রেণির হারে, বছর ধরে — অবশিষ্ট মূল্যের উপর আর কেনা দামের উপর, জানা অঙ্কে; খাতা বনাম কর পাশাপাশি।
 *   · বিদায়ের লাভ-লোকসান, পুরো ক্ষয় হওয়া, মেরামতের খরচ — প্রতিবেদনে ঠিক অঙ্ক।
 *   · প্রতিটা প্রতিবেদন ওয়েবের দরজায় খোলে; ড্যাশবোর্ড শাখার দেয়াল মানে।
 */
final class TheRegisterAndTheLedgerToldTheSameStoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private FixedAssetService $assets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        $this->assets = app(FixedAssetService::class);
    }

    public function test_the_movement_schedule_adds_up_and_ends_where_the_register_ends(): void
    {
        $category = $this->category();
        $van = $this->asset('Van', '120000', $category);
        $desk = $this->asset('Desk', '60000', $category);
        foreach (['2026-07-01', '2026-08-01'] as $month) {
            $this->assets->runFor($month);
        }

        $events = app(AssetEventService::class);
        $events->addition($van->fresh(), ['amount' => '12000', 'happened_on' => '2026-08-10', 'funded_by' => FixedAssetService::FUNDED_MONEY, 'funding_account_id' => $this->cash()]);
        $events->impair($desk->fresh(), ['amount' => '50000', 'happened_on' => '2026-08-20', 'reason' => 'Water damage']);
        $this->assets->dispose($desk->fresh(), '45000', $this->cash(), '2026-08-25');

        $rows = app(ReportEngine::class)->run(FixedAssetReports::MOVEMENT, ['from' => '2026-08-01', 'to' => '2026-08-31'])->rows;
        $this->assertCount(1, $rows);
        $r = (array) $rows[0];

        $cost = bcsub(bcadd(bcadd((string) $r['opening_cost'], (string) $r['additions'], 4), (string) $r['revalued'], 4), (string) $r['disposed_cost'], 4);
        $this->assertSame(0, bccomp($cost, (string) $r['closing_cost'], 4), '⛔ দামের চলাচল মেলে না: '.json_encode($r));
        $acc = bcsub(bcadd(bcadd(bcadd((string) $r['opening_acc'], (string) $r['charge'], 4), (string) $r['impaired'], 4), (string) $r['revalued_acc'], 4), (string) $r['disposed_acc'], 4);
        $this->assertSame(0, bccomp($acc, (string) $r['closing_acc'], 4), '⛔ সঞ্চিত ক্ষয়ের চলাচল মেলে না: '.json_encode($r));

        // ⓘ শুরু: জুলাইয়ের পরে দুইটাই — ১,৮০,০০০ দাম, ৩,০০০ ক্ষয়; শেষ: কেবল ভ্যান, ১,৩২,০০০ দাম
        $this->assertSame(0, bccomp((string) $r['opening_cost'], '180000', 4));
        $this->assertSame(0, bccomp((string) $r['opening_acc'], '3000', 4));
        $this->assertSame(0, bccomp((string) $r['closing_cost'], '132000', 4), '⛔ শেষের দাম নিবন্ধনের নয়।');
        $this->assertSame(0, bccomp((string) $r['closing_acc'], $van->fresh()->accumulated(), 4));
        $this->assertSame(0, bccomp((string) $r['impaired'], '8000', 4), '⛔ ৫৮,০০০ − ৫০,০০০ দাম পড়া তফসিলে নেই।');
    }

    public function test_the_register_equals_the_ledger_and_a_hand_journal_is_caught(): void
    {
        $category = $this->category();
        $van = $this->asset('Van', '120000', $category);
        $this->assets->runFor('2026-07-01');
        app(AssetEventService::class)->impair($van->fresh(), ['amount' => '100000', 'happened_on' => '2026-07-31', 'reason' => 'Accident']);

        $check = FixedAssetChecks::registerAgreesWithTheLedger();
        $this->assertSame([], $check->run(), '⛔ নিবন্ধন আর খাতা মেলার কথা, অথচ যাচাই অমিল বলল।');

        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: 987654, trxDate: '2026-07-31', lines: [
            ['account_id' => $category->asset_account_id, 'debit' => '5000'],
            ['account_id' => $this->cash(), 'credit' => '5000'],
        ]);

        $findings = $check->run();
        $this->assertCount(1, $findings, '⛔ সম্পদের খাতে হাতে বসানো ৫,০০০ যাচাই ধরেনি।');
        $this->assertStringContainsString('5,000', $findings[0]->detail);
    }

    public function test_tax_depreciation_follows_the_category_rate_year_by_year_beside_the_book(): void
    {
        $reducing = $this->category('PLANT', '20', AssetTaxService::REDUCING);
        $straight = $this->category('FURN', '10', AssetTaxService::STRAIGHT);
        $plant = $this->asset('Generator', '120000', $reducing);
        $chair = $this->asset('Chairs', '50000', $straight);
        $this->assets->runFor('2026-07-01');

        $done = app(AssetTaxService::class)->run('2028-03-01');
        $this->assertSame('2028-06-30', $done['year_end'], 'আয়বর্ষ জুলাই-জুন।');

        $years = fn (FixedAsset $a) => AssetTaxYear::query()->where('fixed_asset_id', $a->id)->orderBy('year_end')->pluck('amount')->map(fn ($v) => (string) $v)->all();
        $this->assertSame(['24000.0000', '19200.0000'], $years($plant), '⛔ অবশিষ্ট মূল্যের উপর ২০%: ২৪,০০০ তারপর ১৯,২০০ নয়।');
        $this->assertSame(['5000.0000', '5000.0000'], $years($chair), '⛔ কেনা দামের উপর ১০%: প্রতি বছর ৫,০০০ নয়।');

        app(AssetTaxService::class)->run('2028-03-01');
        $this->assertSame(2, AssetTaxYear::query()->where('fixed_asset_id', $plant->id)->count(), '⛔ আবার হিসাবে বছর দুইবার বসল।');

        $rows = collect(app(ReportEngine::class)->run(FixedAssetReports::BOOK_VS_TAX, ['from' => '2027-06-30', 'to' => '2027-06-30'])->rows)
            ->map(fn ($r) => (array) $r)->keyBy('name');
        $this->assertSame(0, bccomp((string) $rows['Generator']['tax'], '24000', 4));
        $this->assertSame(0, bccomp((string) $rows['Generator']['book'], '2000', 4), 'খাতায় কেবল জুলাইয়ের অবচয় বসেছে।');
        $this->assertSame(0, bccomp((string) $rows['Generator']['difference'], '22000', 4));
    }

    public function test_disposals_fully_depreciated_and_maintenance_show_the_right_figures(): void
    {
        $category = $this->category();
        $sold = $this->asset('Sold van', '120000', $category);
        $this->assets->runFor('2026-07-01');
        $this->assets->dispose($sold->fresh(), '125000', $this->cash(), '2026-08-05');

        $old = $this->asset('Old fan', '1000', $category, ['life_months' => 1, 'salvage' => '100']);
        $this->assets->depreciate($old, '2026-07-01');

        app(AssetEventService::class)->repair($old->fresh(), ['amount' => '300', 'happened_on' => '2026-08-02', 'funded_by' => FixedAssetService::FUNDED_ALREADY]);
        app(AssetEventService::class)->repair($old->fresh(), ['amount' => '200', 'happened_on' => '2026-08-09', 'funded_by' => FixedAssetService::FUNDED_ALREADY]);

        $engine = app(ReportEngine::class);
        $range = ['from' => '2026-08-01', 'to' => '2026-08-31'];

        $out = (array) $engine->run(FixedAssetReports::DISPOSALS, $range)->rows[0];
        $this->assertSame(0, bccomp((string) $out['gain_loss'], '7000', 4), '⛔ ১,২৫,০০০ − ১,১৮,০০০ লাভ নয়।');

        $tired = collect($engine->run(FixedAssetReports::FULLY_DEPRECIATED, $range)->rows)->pluck('name')->all();
        $this->assertSame(['Old fan'], $tired, '⛔ পুরো ক্ষয় হওয়া চালু সম্পদ তালিকায় নেই, বা অন্যটা ঢুকেছে।');

        $repairs = (array) $engine->run(FixedAssetReports::MAINTENANCE, $range)->rows[0];
        $this->assertSame(2, (int) $repairs['repairs']);
        $this->assertSame(0, bccomp((string) $repairs['amount'], '500', 4), '⛔ মেরামতের খরচের যোগ ভুল।');
    }

    public function test_every_report_opens_at_its_web_door_and_the_dashboard_keeps_the_branch_wall(): void
    {
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        $this->asset('Mymensingh van', '70000', null, ['branch_id' => $a->id, 'warranty_ends_on' => now()->addDays(10)->toDateString()]);
        $this->asset('Netrakona van', '90000', null, ['branch_id' => $b->id]);

        foreach (['asset-register', 'asset-depreciation', 'asset-movement', 'asset-nbv', 'asset-disposals', 'asset-fully-depreciated',
            'asset-expiring', 'asset-variance', 'asset-book-vs-tax', 'asset-maintenance'] as $slug) {
            $this->get(route('accounts.report.show', ['slug' => $slug]))->assertOk();
        }

        $this->get(route('accounts.asset.dashboard'))->assertOk()->assertSee('1,60,000')->assertSee('Mymensingh van');
        $this->get(route('accounts.asset.tax'))->assertOk();

        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => null, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, function () use ($clerk) {
            $clerk->givePermissionTo(Permission::findOrCreate('accounts.asset.view', 'web'));
            $clerk->givePermissionTo(Permission::findOrCreate('accounts.report', 'web'));
        });
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $a->id,
        ]);
        app(DataScope::class)->forget();

        $this->actingAs($clerk->fresh())->get(route('accounts.asset.dashboard'))->assertOk()
            ->assertSee('70,000')->assertDontSee('1,60,000');
        $this->get(route('accounts.report.show', ['slug' => 'asset-register', 'to' => now()->toDateString()]))->assertOk()
            ->assertSee('Mymensingh van')->assertDontSee('Netrakona van');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $over */
    private function asset(string $name, string $cost, ?AssetCategory $category, array $over = []): FixedAsset
    {
        $accounts = $category !== null ? ['category_id' => $category->id] : [
            'asset_account_id' => Account::query()->postable()->where('code', '1202')->value('id'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)->id,
        ];

        // ⓘ টাকা দিয়ে কেনা — দাম খাতায় বসে, তাই নিবন্ধন আর খাতা মেলার কথা
        return $this->assets->register([
            ...$accounts, 'name' => $name, 'acquired_on' => '2026-07-01', 'cost' => $cost, 'salvage' => '0',
            'method' => FixedAsset::STRAIGHT_LINE, 'life_months' => 60,
            'funded_by' => FixedAssetService::FUNDED_MONEY, 'funding_account_id' => $this->cash(), ...$over,
        ]);
    }

    private function category(string $code = 'VEH', ?string $taxRate = null, ?string $taxMethod = null): AssetCategory
    {
        return app(AssetCategoryService::class)->create([
            'code' => $code, 'name_en' => $code,
            'asset_account_id' => Account::query()->postable()->where('code', '1202')->value('id'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)->id,
            'gain_account_id' => null, 'loss_account_id' => null,
            'impairment_account_id' => Account::query()->postable()->where('code', '5206')->value('id'),
            'method' => FixedAsset::STRAIGHT_LINE, 'life_months' => 60, 'residual_percent' => '0',
            'tax_rate' => $taxRate, 'tax_method' => $taxMethod,
        ]);
    }

    private function cash(): int
    {
        return (int) Account::query()->money()->postable()->orderBy('code')->value('id');
    }
}
