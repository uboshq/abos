<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ উপহার দেওয়ার পর্দা — কেবল নিজের নাগালের শাখার কাগজের উপহার (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, প্রমোশন ২১;
 * [[PromotionGiftController]])।
 *
 * ⓘ অফারের প্রয়োগে শাখা ছিল না, তাই ময়মনসিংহে সীমিত কর্মী নেত্রকোনার বিলের বাকি উপহার দেখতেন আর ঠিকানা বসিয়ে দিয়েও দিতে পারতেন।
 */
final class AGiftIsIssuedOnlyInsideMyBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_branchs_gift_is_neither_listed_nor_issued(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        $ours = $this->giftOn('PROM-GIFT-MMS', 'Mymensingh gift', $mms, $owner);
        $theirs = $this->giftOn('PROM-GIFT-NTK', 'Netrokona gift', $ntk, $owner);

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('promotion.gift', 'web')));
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mms->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        $this->actingAs($clerk->fresh())->get(route('promotion.gift.index'))->assertOk()
            ->assertSee('PROM-GIFT-MMS')
            ->assertDontSee('PROM-GIFT-NTK');

        $this->post(route('promotion.gift.store', $theirs), ['warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'qty' => '1'])
            ->assertForbidden();
        $this->assertSame(0, $theirs->gifts()->count(), '⛔ অন্য শাখার কাগজের উপহার দেওয়া হলো');
        $this->assertNotNull($ours);
    }

    private function giftOn(string $code, string $name, Branch $branch, User $owner): PromotionApplication
    {
        $offer = new Promotion(['name_en' => $name, 'starts_on' => Carbon::today()->subDay(), 'ends_on' => Carbon::today()->addWeek()]);
        $offer->code = $code;
        $offer->type = PromotionType::QUANTITY_SLAB;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $owner->id;
        $offer->save();

        $benefit = PromotionBenefit::query()->create(['promotion_id' => $offer->id, 'kind' => BenefitKind::GOODS, 'amount' => '2',
            'gift_product_id' => Product::query()->where('is_active', true)->orderBy('id')->value('id')]);

        return PromotionApplication::query()->create([
            'promotion_id' => $offer->id, 'branch_id' => $branch->id, 'source_type' => 'delivery_challan', 'source_id' => random_int(1000, 9999),
            'promotion_benefit_id' => $benefit->id, 'benefit_kind' => BenefitKind::GOODS, 'benefit_amount' => '2', 'worth' => '20',
        ]);
    }
}
