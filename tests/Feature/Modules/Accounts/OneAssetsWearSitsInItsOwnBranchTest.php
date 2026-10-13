<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AssetCategory;
use App\Modules\Accounts\Models\DepreciationEntry;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\AssetCategoryService;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ এক সম্পদের এক মাসের অবচয় সম্পদের শাখায় বসে — অডিট ম১৫, ১০ অক্টোবর ২০২৬।
 *
 * ⓘ [[FixedAssetService::depreciate()]] দাখিলায় শাখা দিত না, তাই ইঞ্জিন বসাত যিনি চালালেন তাঁর দেখার শাখায়: NTK-র ভ্যানের
 * ক্ষয় MMS-এর লাভ-ক্ষতিতে পড়ত, আর NTK-র সঞ্চিত ক্ষয় কম দেখাত। কেনা, বিদায়, স্থানান্তর আর মাসের দৌড় আগেই সম্পদের শাখায়।
 */
final class OneAssetsWearSitsInItsOwnBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_months_wear_lands_in_the_assets_branch_not_the_runners(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();

        CompanyContext::set($company->id, $mms->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $van = app(FixedAssetService::class)->register([
            'category_id' => $this->category()->id, 'name' => 'NTK Van', 'acquired_on' => '2026-07-01', 'cost' => '12000', 'salvage' => '0',
            'method' => FixedAsset::STRAIGHT_LINE, 'life_months' => 12, 'funded_by' => FixedAssetService::FUNDED_ALREADY, 'branch_id' => $ntk->id,
        ]);
        $this->assertSame((int) $ntk->id, (int) $van->branch_id, 'দৃশ্যটাই বানানো যায়নি — ভ্যানটা NTK-র নয়');

        $entry = app(FixedAssetService::class)->depreciate($van->fresh(), '2026-07-01');
        $this->assertInstanceOf(DepreciationEntry::class, $entry);

        $branches = LedgerEntry::query()->withoutGlobalScopes()
            ->where('source_type', DepreciationEntry::drillSourceType())->where('source_id', $entry->id)
            ->pluck('branch_id')->map(fn ($b) => (int) $b)->unique()->values()->all();

        $this->assertSame([(int) $ntk->id], $branches, '⛔ NTK-র ভ্যানের ক্ষয় যিনি চালালেন তাঁর শাখায় (MMS) বসেছে');
    }

    private function category(): AssetCategory
    {
        return AssetCategory::query()->where('code', 'VEH')->first() ?? app(AssetCategoryService::class)->create([
            'code' => 'VEH', 'name_en' => 'Vehicles', 'name_bn' => 'যানবাহন',
            'asset_account_id' => (int) Account::query()->postable()->where('code', '1202')->value('id'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)->id,
            'gain_account_id' => StandardChart::find(StandardChart::ASSET_DISPOSAL_GAIN)->id,
            'loss_account_id' => StandardChart::find(StandardChart::ASSET_DISPOSAL_LOSS)->id,
            'impairment_account_id' => null,
            'method' => FixedAsset::STRAIGHT_LINE, 'life_months' => 12, 'residual_percent' => '0',
        ]);
    }
}
