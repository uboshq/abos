<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\BranchModule;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Models\PromotionScope;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\ConditionKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use App\Modules\Promotion\Support\ScopeKind;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনে চলতি অফার — SR দোকানে দাঁড়িয়ে দেখেন কোন পণ্যে কী (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬; [[PromotionApiController]])।
 *
 * দাবি:
 *  - কেবল চলতি — খসড়া, মেয়াদ পেরোনো আর কুপনের অফার আসে না; নিয়মটা কাঠামোয় (কত কিনলে, কী, কোন পণ্যে, কবে পর্যন্ত)।
 *  - অন্য শাখায় বাঁধা অফার এই শাখার চোখে নেই।
 *  - ⛔ নির্দিষ্ট দোকানের অফার বিক্রয়কর্মী পান কেবল নিজের দোকানের হলে, আর নাম কেবল নিজের দোকানের।
 *  - চাবি ছাড়া ৪০৩; ফোনে বা কোম্পানিতে প্রচার বন্ধ ৪০৩ `module_off`; এই শাখায় বন্ধ হলে খালি, কারণসহ।
 */
final class ThePhoneShowsTheRunningOffersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private int $serial = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(SettingsService::class)->set(PhoneModules::key('promotion'), true);
    }

    public function test_only_running_offers_come_and_the_rule_comes_whole(): void
    {
        $product = Product::query()->orderBy('id')->firstOrFail();
        $gift = Product::query()->orderByDesc('id')->firstOrFail();

        $live = $this->offer('LIVE');
        PromotionScope::query()->create(['promotion_id' => $live->id, 'kind' => ScopeKind::PRODUCT, 'target_id' => $product->id]);
        $step = PromotionCondition::query()->create(['promotion_id' => $live->id, 'kind' => ConditionKind::QUANTITY, 'value_from' => '10', 'step_order' => 0]);
        PromotionBenefit::query()->create(['promotion_id' => $live->id, 'promotion_condition_id' => $step->id,
            'kind' => BenefitKind::GOODS, 'amount' => '1', 'gift_product_id' => $gift->id]);

        $draft = $this->offer('DRAFT', status: PromotionStatus::DRAFT);
        $ended = $this->offer('ENDED', ends: Carbon::yesterday());
        $coupon = $this->offer('COUPON', type: PromotionType::COUPON);

        $rows = $this->phone($this->owner)->assertOk()->assertJsonPath('enabled', true)->json('rows');
        $ids = array_column($rows, 'id');

        $this->assertContains($live->public_id, $ids);
        $this->assertNotContains($draft->public_id, $ids, '⛔ খসড়া অফার ফোনে।');
        $this->assertNotContains($ended->public_id, $ids, '⛔ মেয়াদ পেরোনো অফার ফোনে।');
        $this->assertNotContains($coupon->public_id, $ids, '⛔ কুপনের অফার কোড ছাড়া ফোনে।');

        $row = collect($rows)->firstWhere('id', $live->public_id);
        $this->assertSame([$product->name('bn')], $row['products']);
        $this->assertSame(Carbon::today()->addWeek()->toDateString(), $row['ends_on']);
        $this->assertSame('quantity', $row['steps'][0]['kind']);
        $this->assertSame('10', $row['steps'][0]['from']);
        $this->assertSame('goods', $row['steps'][0]['benefits'][0]['kind']);
        $this->assertSame('1', $row['steps'][0]['benefits'][0]['amount']);
        $this->assertSame($gift->name('bn'), $row['steps'][0]['benefits'][0]['gift']);
    }

    public function test_an_offer_held_to_another_branch_is_not_on_this_branchs_phone(): void
    {
        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('id', '!=', CompanyContext::branchId())->orderBy('id')->firstOrFail();
        $theirs = $this->offer('THEIRS');
        PromotionScope::query()->create(['promotion_id' => $theirs->id, 'kind' => ScopeKind::BRANCH, 'target_id' => $other->id]);
        $ours = $this->offer('OURS');
        PromotionScope::query()->create(['promotion_id' => $ours->id, 'kind' => ScopeKind::BRANCH, 'target_id' => CompanyContext::branchId()]);

        $owner = $this->owner->fresh();
        $owner->forceFill(['current_branch_id' => CompanyContext::branchId()])->save();

        $ids = array_column($this->phone($owner)->assertOk()->json('rows'), 'id');
        $this->assertContains($ours->public_id, $ids);
        $this->assertNotContains($theirs->public_id, $ids, '⛔ অন্য শাখার অফার এই শাখার ফোনে।');
    }

    public function test_a_salesman_sees_a_shop_offer_only_for_his_own_shops_and_only_their_names(): void
    {
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        CompanyContext::forCompany($this->company->id, fn () => $sales->givePermissionTo(Permission::findOrCreate('promotion.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($sales->fresh());
        $his = Customer::query()->orderBy('id')->first();
        $this->assertNotNull($his, 'দাবির ভিত্তি নেই — বিক্রয়কর্মীর কোনো দোকান নেই।');
        $other = Customer::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->whereNotIn('id', Customer::query()->pluck('id')->all())->orderBy('id')->first();
        $this->assertNotNull($other, 'দাবির ভিত্তি নেই — বিক্রয়কর্মী সব দোকান দেখেন, দেয়াল যাচাই হয় না।');
        $this->actingAs($this->owner);

        $notHis = $this->offer('NOT-HIS');
        PromotionScope::query()->create(['promotion_id' => $notHis->id, 'kind' => ScopeKind::CUSTOMER, 'target_id' => $other->id]);
        $mixed = $this->offer('MIXED');
        PromotionScope::query()->create(['promotion_id' => $mixed->id, 'kind' => ScopeKind::CUSTOMER, 'target_id' => $other->id]);
        PromotionScope::query()->create(['promotion_id' => $mixed->id, 'kind' => ScopeKind::CUSTOMER, 'target_id' => $his->id]);

        $rows = $this->phone($sales)->assertOk()->json('rows');
        $ids = array_column($rows, 'id');

        $this->assertNotContains($notHis->public_id, $ids, '⛔ অন্যের দোকানের অফার বিক্রয়কর্মীর ফোনে।');
        $this->assertContains($mixed->public_id, $ids);
        $this->assertSame([(string) $his->name()], collect($rows)->firstWhere('id', $mixed->public_id)['shops'],
            '⛔ অন্যের দোকানের নাম বিক্রয়কর্মীর ফোনে।');
    }

    public function test_without_the_key_or_with_promotion_off_it_says_so(): void
    {
        $this->offer('ANY');
        $stranger = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $stranger->companies()->attach($this->company, ['is_active' => true]);
        $this->phone($stranger)->assertForbidden();

        // ⓘ এই শাখায় প্রচার বন্ধ — অফার নেই, কারণসহ
        // ⭐ এখন ফোনের দরজাই বন্ধ (৪০৩ module_off), ওয়েবের মতো — আগে দরজা খুলে কেবল "enabled: false" বলত, কারণ ফোন শাখার
        // সুইচ পড়ত না ([[PhoneModules::isOn()]]; ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬)
        // ⓘ শাখাটা কোম্পানির নিজের — আগের ফোন-ডাক প্রসঙ্গটা অপরিচিতের (শাখাহীন) করে রেখে গেছে
        $branch = (int) $this->company->defaultBranch()?->id;
        $owner = $this->owner->fresh();
        $owner->forceFill(['current_branch_id' => $branch])->save();
        BranchModule::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $branch, 'module' => 'promotion', 'is_enabled' => false,
        ]);
        $this->phone($owner)->assertForbidden()->assertJsonPath('reason', PhoneModules::REASON);

        // ⓘ কোম্পানিতে প্রচার বন্ধ হলে ফোনের দরজাই বন্ধ — মডিউলের সুইচ ([[PhoneModules::isOn()]])
        app(SettingsService::class)->set('promotion.enabled', false);
        $this->phone($owner)->assertForbidden()->assertJsonPath('reason', PhoneModules::REASON);
        app(SettingsService::class)->set('promotion.enabled', true);

        app(SettingsService::class)->set(PhoneModules::key('promotion'), false);
        $this->phone($this->owner)->assertForbidden()->assertJsonPath('reason', PhoneModules::REASON);
    }

    private function offer(string $tag, PromotionStatus $status = PromotionStatus::ACTIVE, ?Carbon $ends = null,
        PromotionType $type = PromotionType::QUANTITY_SLAB): Promotion
    {
        $this->serial++;
        $offer = new Promotion([
            'name_en' => 'Offer '.$tag,
            'name_bn' => 'অফার '.$tag,
            'starts_on' => Carbon::today()->subDays(10),
            'ends_on' => $ends ?? Carbon::today()->addWeek(),
            'priority' => 0,
        ]);
        $offer->code = sprintf('PROM-PH-%04d', $this->serial);
        $offer->type = $type;
        $offer->status = $status;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        return $offer->fresh();
    }

    private function phone(User $user): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson('/api/v1/promotion/offers');
    }
}
