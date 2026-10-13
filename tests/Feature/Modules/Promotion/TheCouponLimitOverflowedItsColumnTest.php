<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCoupon;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⛔ কুপনের "কতবার" ঘরে কলামের চেয়ে বড় সংখ্যা — ভুলের বার্তা, ৫০০ নয় (Promotion লাইন-ধরা নিরীক্ষা, ১৪ অক্টোবর ২০২৬;
 * [[PromotionCouponController::store()]])।
 *
 * ⓘ `integer` নিয়ম ৫০০ কোটি মেনে নিত, অথচ কলামটা `unsignedInteger` (সর্বোচ্চ ৪,২৯৪,৯৬৭,২৯৫) — ইনসার্ট ভাঙত আর পাতা ৫০০ দিত।
 * ⓘ পাল্টা-দাবি: সাধারণ সংখ্যায় কুপন তৈরি হয়, তাই থামাটা সংখ্যার আকারের জন্যই।
 */
final class TheCouponLimitOverflowedItsColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_use_limit_beyond_the_column_is_a_form_error_and_a_normal_one_is_made(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->givePermissionTo(Permission::findOrCreate('promotion.coupon', 'web'));
        $this->actingAs($owner);

        $offer = new Promotion(['name_en' => 'Overflow coupon', 'starts_on' => Carbon::today()->subDay(), 'ends_on' => Carbon::today()->addWeek()]);
        $offer->code = 'PROM-CO-OVF';
        $offer->type = PromotionType::COUPON;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $owner->id;
        $offer->save();
        PromotionBenefit::query()->create(['promotion_id' => $offer->id, 'kind' => BenefitKind::AMOUNT, 'amount' => '100']);

        $this->post(route('promotion.coupon.store', $offer), ['code' => 'BIGLIMIT', 'max_uses' => '5000000000'])
            ->assertSessionHasErrors('max_uses');
        $this->post(route('promotion.coupon.store', $offer), ['code' => 'BIGPER', 'max_uses' => '10', 'max_uses_per_customer' => '5000000000'])
            ->assertSessionHasErrors('max_uses_per_customer');
        $this->assertSame(0, PromotionCoupon::query()->where('promotion_id', $offer->id)->count(), '⛔ কলামের বাইরের সংখ্যায় কুপন তৈরি হলো');

        $this->post(route('promotion.coupon.store', $offer), ['code' => 'NORMAL10', 'max_uses' => '10'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, PromotionCoupon::query()->where('promotion_id', $offer->id)->count());
    }
}
