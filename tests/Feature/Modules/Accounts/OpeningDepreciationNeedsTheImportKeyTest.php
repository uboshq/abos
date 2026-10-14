<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AssetCategory;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\AssetCategoryService;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⛔ খোলা অবচয় কেবল আগের-সম্পদ আনার চাবিতে — Accounts লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⓘ খোলা অবচয় সই ছাড়াই ধরে রাখা মুনাফা থেকে কাটে। মডিউল বলে ওটা `accounts.asset.import` চাবির কাজ, অথচ সাধারণ "সম্পদ যোগ"-এর
 * পর্দা কেবল `manage` চাবিতেই ৫ লাখের সম্পদে ৫ লাখ খোলা অবচয় বসাতে দিত — মূলধন ৫ লাখ কমত, কেউ সই না করেই।
 */
final class OpeningDepreciationNeedsTheImportKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_import_key_enters_opening_depreciation(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $category = AssetCategory::query()->where('code', 'VEH')->first() ?? app(AssetCategoryService::class)->create([
            'code' => 'VEH', 'name_en' => 'Vehicles', 'name_bn' => 'যানবাহন',
            'asset_account_id' => (int) Account::query()->postable()->where('code', '1202')->value('id'),
            'accumulated_account_id' => StandardChart::find(StandardChart::ACCUMULATED_DEPRECIATION)->id,
            'expense_account_id' => StandardChart::find(StandardChart::DEPRECIATION_EXPENSE)->id,
            'gain_account_id' => StandardChart::find(StandardChart::ASSET_DISPOSAL_GAIN)->id,
            'loss_account_id' => StandardChart::find(StandardChart::ASSET_DISPOSAL_LOSS)->id,
            'impairment_account_id' => null, 'method' => FixedAsset::STRAIGHT_LINE, 'life_months' => 60, 'residual_percent' => '0',
        ]);

        $clerk = $this->staff($company, ['accounts.asset.view', 'accounts.asset.manage']);
        $form = fn (string $name) => ['category_id' => $category->id, 'name' => $name, 'acquired_on' => now()->subYear()->toDateString(),
            'cost' => '500000', 'opening_accumulated' => '500000', 'funded_by' => FixedAssetService::FUNDED_ALREADY];

        $this->actingAs($clerk)->from(route('accounts.asset.index'))->post(route('accounts.asset.store'), $form('Old van'))
            ->assertSessionHasErrors(['opening_accumulated' => __('accounts::asset.opening_needs_import_key')]);
        $this->assertFalse(FixedAsset::acrossBranches()->where('name', 'Old van')->exists(), '⛔ চাবি ছাড়া খোলা অবচয়সহ সম্পদ বসল');

        // ⓘ আমদানির চাবিতে — চলে
        $importer = $this->staff($company, ['accounts.asset.view', 'accounts.asset.manage', 'accounts.asset.import']);
        $this->actingAs($importer)->post(route('accounts.asset.store'), $form('Old truck'))->assertSessionDoesntHaveErrors('opening_accumulated');
    }

    /** @param list<string> $keys */
    private function staff(Company $company, array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $user->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $user->givePermissionTo(array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));

        return $user->fresh();
    }
}
