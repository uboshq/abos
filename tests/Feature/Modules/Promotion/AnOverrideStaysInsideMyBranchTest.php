<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
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
 * ⛔ অফারের হাতে-বদল — কেবল নিজের নাগালের শাখার কাগজে (Promotion লাইন-ধরা নিরীক্ষা, ১৪ অক্টোবর ২০২৬;
 * [[PromotionOverrideController]])।
 *
 * ⓘ উপহারের দরজায় শাখার পাহারা ছিল ([[AGiftIsIssuedOnlyInsideMyBranchTest]]), হাতে-বদলের দরজায় ছিল না: ময়মনসিংহে সীমিত মানুষ
 * নেত্রকোনার আদেশের সারির নম্বর ঠিকানায় বসিয়ে ছাড়ের অঙ্ক বদলে দিতে পারতেন। ⓘ একই মানুষ নিজের শাখার সারিতে পারেন — তাই
 * ৪০৩টা চাবি বা সদস্যপদের নয়, শাখার।
 */
final class AnOverrideStaysInsideMyBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_branchs_offer_cannot_be_overridden_and_my_own_can(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        $ours = $this->discountOn('PROM-OVB-MMS', $mms, $owner);
        $theirs = $this->discountOn('PROM-OVB-NTK', $ntk, $owner);

        $manager = User::factory()->create(['current_company_id' => $company->id]);
        $manager->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $manager->givePermissionTo(Permission::findOrCreate('promotion.override', 'web')));
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mms->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        $form = ['worth' => '90', 'override_reason' => 'অন্য শাখার ছাড় বাড়ানোর চেষ্টা'];

        $this->actingAs($manager->fresh())->post(route('promotion.override', $theirs), $form)->assertForbidden();
        $this->assertSame(0, bccomp('10', (string) $theirs->fresh()->worth, 4), '⛔ অন্য শাখার কাগজের ছাড় বদলে গেল');
        $this->assertFalse((bool) $theirs->fresh()->was_overridden);

        $this->post(route('promotion.override', $ours), $form)->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp('90', (string) $ours->fresh()->worth, 4), 'নিজের শাখার বদলও আটকাল — ৪০৩টা শাখার নয়');
    }

    private function discountOn(string $code, Branch $branch, User $owner): PromotionApplication
    {
        $offer = new Promotion(['name_en' => 'Override '.$code, 'starts_on' => Carbon::today()->subDay(), 'ends_on' => Carbon::today()->addWeek()]);
        $offer->code = $code;
        $offer->type = PromotionType::PERCENT_DISCOUNT;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $owner->id;
        $offer->save();

        $benefit = PromotionBenefit::query()->create(['promotion_id' => $offer->id, 'kind' => BenefitKind::AMOUNT, 'amount' => '10']);

        // ⓘ আদেশের নম্বরটা বানানো — কাগজের মালিক ([[SalesCouponPapers::offerChanged()]]) অজানা আদেশে থামায় না, তাই বদলটা সত্যিই বসত
        return PromotionApplication::query()->create([
            'promotion_id' => $offer->id, 'branch_id' => $branch->id, 'source_type' => 'sales_order', 'source_id' => random_int(900000, 999999),
            'promotion_benefit_id' => $benefit->id, 'benefit_kind' => BenefitKind::AMOUNT, 'benefit_amount' => '10', 'worth' => '10',
        ]);
    }
}
