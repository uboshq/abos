<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AssetCategory;
use App\Modules\Accounts\Models\AssetEstimateChange;
use App\Modules\Accounts\Models\DepreciationEntry;
use App\Modules\Accounts\Models\DepreciationRun;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\AssetCategoryService;
use App\Modules\Accounts\Services\DepreciationEngine;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * অবচয় এক এক সম্পদে বসত, একটাই পদ্ধতি-জোড়া, আগে দেখার উপায় ছিল না — স্থায়ী সম্পদ ধাপ ২ (মালিক, ১০ অক্টোবর ২০২৬; IAS 16.50-62)।
 *
 * ⭐ জানা অঙ্কে দাবি: তিন পদ্ধতি, প্রথম মাসের দিন-ভাগ, শেষ দামে থামা, আয়ু বদল আগামীর দিকে, অলস সুইচ; মাসের দৌড় শাখায়
 * একটা কাগজ, খাতের জোড়া ধরে সারি, দুইবার চালালে একবার, বন্ধ মাসে থামে।
 */
final class DepreciationRanOneAssetAtATimeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FixedAssetService $assets;

    private DepreciationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->assets = app(FixedAssetService::class);
        $this->engine = app(DepreciationEngine::class);
    }

    public function test_straight_line_reducing_and_units_on_known_numbers(): void
    {
        $straight = $this->asset(['cost' => '120000', 'life_months' => 60]);
        $reducing = $this->asset(['name' => 'Truck', 'cost' => '100000', 'method' => FixedAsset::REDUCING, 'rate' => '24', 'life_months' => null]);
        $units = $this->asset(['name' => 'Generator', 'cost' => '110000', 'salvage' => '10000', 'method' => FixedAsset::UNITS,
            'total_units' => '100000', 'life_months' => null]);

        $this->assertSame('2000.0000', $this->engine->amountFor($straight, '2026-07-31')['amount'], '⛔ সমান হারে ১,২০,০০০ ÷ ৬০ = ২,০০০ নয়।');
        $this->assertSame('2000.0000', $this->engine->amountFor($reducing, '2026-07-31')['amount'], '⛔ ২৪% বছরে, মাসে ২%: ২,০০০ নয়।');
        $this->assertSame('no_usage', $this->engine->amountFor($units, '2026-07-31')['reason'], 'একক না লিখলে কিছু বসে না।');

        $this->assets->recordUsage($units, '2026-07-01', '2500');
        $this->assertSame('2500.0000', $this->engine->amountFor($units, '2026-07-31')['amount'], '⛔ ১,০০,০০০ × ২,৫০০ ÷ ১,০০,০০০ = ২,৫০০ নয়।');

        $this->assertSame(3, $this->assets->runFor('2026-07-01')['posted']);

        // ⓘ পরের মাস: ক্রমহ্রাসমানে ৯৮,০০০-এর ২% = ১,৯৬০; এককে বাকি ৯৭,৫০০ × ৫,০০০ ÷ ৯৭,৫০০ = ৫,০০০
        $this->assets->recordUsage($units->fresh(), '2026-08-01', '5000');
        $this->assertSame('1960.0000', $this->engine->amountFor($reducing->fresh(), '2026-08-31')['amount']);
        $this->assertSame('5000.0000', $this->engine->amountFor($units->fresh(), '2026-08-31')['amount']);
        $this->assertSame('2000.0000', $this->engine->amountFor($straight->fresh(), '2026-08-31')['amount'], 'বদল না হলে প্রতি মাসে একই অঙ্ক।');
    }

    public function test_the_first_month_by_day_and_stopping_at_the_residual_value(): void
    {
        $asset = $this->asset(['cost' => '60000', 'life_months' => 60, 'put_in_use_on' => '2026-08-21']);

        $this->assertSame('not_started', $this->engine->amountFor($asset, '2026-07-31')['reason'], '⛔ ব্যবহার শুরুর আগে ক্ষয়।');
        $this->assertSame('1000.0000', $this->engine->amountFor($asset, '2026-08-31')['amount'], 'ডিফল্ট পুরো মাস — আজকের আচরণ।');

        app(SettingsService::class)->set(DepreciationEngine::PRORATA, DepreciationEngine::DAILY);
        // ⓘ ২১ থেকে ৩১ আগস্ট — ৩১ দিনের ১১ দিন: ১,০০০ × ১১ ÷ ৩১
        $this->assertSame('354.8387', $this->engine->amountFor($asset, '2026-08-31')['amount'], '⛔ প্রথম মাস দিন ধরে ভাগ হয়নি।');

        $small = $this->asset(['name' => 'Fan', 'cost' => '1000', 'salvage' => '400', 'life_months' => 2]);
        $this->assets->runFor('2026-07-01');
        $this->assets->runFor('2026-08-01');
        $this->assertSame('fully_depreciated', $this->engine->amountFor($small->fresh(), '2026-09-30')['reason']);
        $this->assertSame(0, bccomp($small->fresh()->bookValue(), '400', 4), '⛔ শেষ দামের নিচে নেমে গেল।');
    }

    public function test_the_monthly_run_is_one_paper_per_branch_posts_once_and_stops_at_a_locked_month(): void
    {
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        $vehicles = $this->category('VEH', '1202');
        $furniture = $this->category('FUR', '1201');

        $this->asset(['name' => 'Van', 'cost' => '120000', 'branch_id' => $a->id, 'category_id' => $vehicles->id]);
        $this->asset(['name' => 'Desk', 'cost' => '60000', 'branch_id' => $a->id, 'category_id' => $furniture->id]);
        $this->asset(['name' => 'Bike', 'cost' => '30000', 'branch_id' => $b->id, 'category_id' => $vehicles->id]);

        $first = $this->assets->runFor('2026-07-01');
        $this->assertSame(3, $first['posted']);
        $this->assertSame(0, bccomp($first['total'], '3500', 4), '২,০০০ + ১,০০০ + ৫০০');

        $runs = DepreciationRun::acrossBranches()->where('period_end', '2026-07-31')->get()->keyBy('branch_id');
        $this->assertCount(2, $runs, '⛔ শাখায় একটা কাগজ নয়।');
        $paperA = LedgerEntry::query()->where('source_type', 'depreciation_run')->where('source_id', $runs[$a->id]->id)->get();
        $this->assertSame([$a->id], $paperA->pluck('branch_id')->unique()->values()->all(), '⛔ শাখা A-র কাগজে অন্য শাখার সারি।');
        $this->assertSame(4, $paperA->count(), '⛔ খাতের জোড়া ধরে সারি নয় (দুই শ্রেণি → দুই ডেবিট, দুই ক্রেডিট)।');

        $second = $this->assets->runFor('2026-07-01');
        $this->assertSame(0, $second['posted'], '⛔ দ্বিতীয়বার চালাতে আবার বসল।');
        $this->assertSame(3, DepreciationEntry::query()->where('period_end', '2026-07-31')->count());

        PeriodLock::query()->create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 8,
            'locked_by' => auth()->id(), 'locked_at' => now()]);

        try {
            $this->assets->runFor('2026-08-01');
            $this->fail('⛔ বন্ধ মাসে অবচয় বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('month', $e->errors());
        }

        $this->assertSame(0, DepreciationEntry::query()->where('period_end', '2026-08-31')->count());
    }

    public function test_a_change_of_estimate_is_prospective_and_remembered(): void
    {
        $asset = $this->asset(['cost' => '120000', 'life_months' => 60]);
        $this->assets->runFor('2026-07-01');
        $this->assets->runFor('2026-08-01');

        $this->assets->changeEstimate($asset->fresh(), ['life_months' => 30], 'গাড়িটা বেশি চলছে');

        // ⓘ বসে যাওয়া দুই মাস অক্ষত; বাকি ১,১৬,০০০ বাকি ২৮ মাসে
        $this->assertSame(['2000.0000', '2000.0000'], DepreciationEntry::query()->where('fixed_asset_id', $asset->id)
            ->orderBy('period_end')->pluck('amount')->map(fn ($a) => (string) $a)->all(), '⛔ বদলে অতীতের অবচয় বদলাল।');
        $this->assertSame('4142.8571', $this->engine->amountFor($asset->fresh(), '2026-09-30')['amount'], '⛔ বাকি দাম বাকি আয়ুতে ভাগ হয়নি।');

        $change = AssetEstimateChange::query()->where('fixed_asset_id', $asset->id)->sole();
        $this->assertSame('60', $change->before['life_months']);
        $this->assertSame('30', $change->after['life_months']);
        $this->assertSame('গাড়িটা বেশি চলছে', $change->reason);
    }

    public function test_idle_assets_depreciate_unless_the_owner_stops_them(): void
    {
        $asset = $this->asset(['cost' => '120000', 'life_months' => 60]);
        $this->assets->changeStatus($asset, FixedAsset::IDLE);

        $this->assertSame('2000.0000', $this->engine->amountFor($asset->fresh(), '2026-07-31')['amount'], '⛔ অলস জিনিস ক্ষয় থামাল (IAS 16.55)।');

        app(SettingsService::class)->set(DepreciationEngine::IDLE_STOPS, true);
        $this->assertSame('idle', $this->engine->amountFor($asset->fresh(), '2026-07-31')['reason'], '⛔ মালিকের সুইচ খাটল না।');
    }

    public function test_the_preview_and_run_screens_and_the_command(): void
    {
        $asset = $this->asset(['cost' => '120000', 'life_months' => 60]);

        $this->get(route('accounts.asset.run.preview', ['month' => '2026-07']))->assertOk()->assertSee($asset->name)->assertSee('2,000.00');
        $this->assertSame(0, DepreciationEntry::query()->count(), '⛔ আগে দেখাতেই বসে গেল।');

        $this->post(route('accounts.asset.depreciate'), ['month' => '2026-07'])->assertSessionHasNoErrors();
        $run = DepreciationRun::acrossBranches()->sole();
        $this->get(route('accounts.asset.run.show', $run))->assertOk()->assertSee($asset->name);
        $this->get(route('accounts.asset.show', $asset))->assertOk()->assertSee(__('accounts::asset.estimate_title'));

        // ⓘ কমান্ড — সুইচ বন্ধে কিছুই নয়, চালু করলে বসে
        $this->artisan('abos:depreciate', ['--company' => 'TDEPOT', '--month' => '2026-08'])->assertSuccessful();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->assertSame(0, DepreciationEntry::query()->where('period_end', '2026-08-31')->count(), '⛔ সুইচ বন্ধ, তবু নিজে বসল।');

        app(SettingsService::class)->set(DepreciationEngine::AUTO_RUN, true);
        $this->artisan('abos:depreciate', ['--company' => 'TDEPOT', '--month' => '2026-08'])->assertSuccessful();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->assertSame(1, DepreciationEntry::query()->where('period_end', '2026-08-31')->count(), '⛔ সুইচ চালু, তবু বসেনি।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $over */
    private function asset(array $over = []): FixedAsset
    {
        // ⓘ শ্রেণি দিলে খাতগুলো শ্রেণির — হাতের খাত দিলে শ্রেণির খাত হেরে যেত
        $accounts = isset($over['category_id']) ? [] : [
            'asset_account_id' => Account::query()->postable()->where('code', '1202')->value('id'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)->id,
        ];

        return $this->assets->register([
            ...$accounts,
            'name' => 'Delivery Van',
            'acquired_on' => '2026-07-01',
            'cost' => '120000',
            'salvage' => '0',
            'method' => FixedAsset::STRAIGHT_LINE,
            'life_months' => 60,
            'funded_by' => FixedAssetService::FUNDED_ALREADY,
            ...$over,
        ]);
    }

    private function category(string $code, string $assetCode): AssetCategory
    {
        // ⓘ দুই শ্রেণির অবচয় খরচ দুই খাতে — খাতের জোড়া ধরে সারি দেখতে
        $expense = $code === 'VEH'
            ? StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)->id
            : Account::query()->postable()->where('code', '5206')->value('id');

        return app(AssetCategoryService::class)->create([
            'code' => $code, 'name_en' => $code, 'asset_account_id' => Account::query()->postable()->where('code', $assetCode)->value('id'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)->id,
            'expense_account_id' => $expense, 'gain_account_id' => null, 'loss_account_id' => null, 'impairment_account_id' => null,
            'method' => FixedAsset::STRAIGHT_LINE, 'life_months' => 60, 'residual_percent' => '0',
        ]);
    }
}
