<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCoupon;
use App\Modules\Promotion\Services\CouponDesk;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * কুপনের কোড ছিল, অথচ কোনো পর্দা ছিল না — স্পেক §৭-ঞ।
 *
 * ── ⚠️ কী ঘটছিল ────────────────────────────────────────────────────
 * ⓘ [[CouponDesk]] কোড বানাত, গুনত, তালা দিত — কিন্তু কেবল পরীক্ষা তাকে
 * ডাকত। ⛔ কুপন-ধরনের অফার চালু হত, অথচ কোড তৈরির বা কাউন্টারে খাটানোর
 * কোনো দরজা ছিল না — অফারটা কোনোদিন কোনো বিলে বসত না, আর কিছু লাল হত না।
 *
 * ── ⭐ প্রতিটা দাবি বিপজ্জনক ইনপুট খায়, আর পাশে একটা পাল্টা-দাবি ─────
 * ⓘ চাবির দাবি একই মানুষকে দুইবার দেখে — চাবি ছাড়া ৪০৩, চাবি পেলে
 * ২০০। ⚠️ দুইজন আলাদা মানুষ হলে কোম্পানির সদস্যপদ বা সুইচও ৪০৩ দিতে
 * পারত, আর দাবিটা চাবির কিছুই প্রমাণ করত না।
 */
final class TheCouponCodesHadNoScreenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Product $product;

    private Promotion $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /*
         * ⓘ চাবি দুইটা মালিককে হাতে দেওয়া — `module.php`-এর ঘোষণার উপর
         * ভর না করে। ⚠️ ঘোষণার আগে সিডার এই চাবি জানত না, আর তখন মালিকের
         * ৪০৩ পড়ত এমন কারণে যেটা এই পরীক্ষা মাপছে না।
         */
        $this->owner->givePermissionTo([
            Permission::findOrCreate('promotion.coupon', 'web'),
            Permission::findOrCreate('promotion.apply', 'web'),
        ]);

        $this->actingAs($this->owner);

        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->offer = $this->aCouponOffer('PROM-CS-0001');
    }

    private function aCouponOffer(string $code): Promotion
    {
        $offer = new Promotion([
            'name_en' => 'Screen coupon '.$code,
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = $code;
        $offer->type = PromotionType::COUPON;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        /* ⓘ শর্তহীন ১০০ টাকা ছাড় — কোডটাই একমাত্র চাবি */
        PromotionBenefit::query()->create([
            'promotion_id' => $offer->id,
            'kind' => BenefitKind::AMOUNT,
            'amount' => '100',
        ]);

        return $offer;
    }

    /** @param  array<string, mixed>  $form */
    private function issueThrough(array $form, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->post(route('promotion.coupon.store', $this->offer), $form + ['max_uses' => 1]);
    }

    /**
     * ⚠️ `post()`, `postJson()` নয় — ইচ্ছা করে।
     *
     * ⓘ কাউন্টারের fetch সবসময় `Accept: application/json` পাঠায় না।
     * ⛔ `postJson` দিলে Laravel নিজেই ৪২২ দিত, আর দরজার হাতে-বানানো ৪২২
     * না থাকলেও দাবিটা সবুজ হত — ঠিক যে ফাঁদটা ধরার কথা, সেটাই ঢাকা পড়ত।
     *
     * @param  array<string, mixed>  $overrides
     */
    private function redeemAtCounter(string $code, array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->post(route('promotion.coupon.redeem'), $overrides + [
                'code' => $code,
                'source_type' => 'sales_invoice',
                'source_id' => 9301,
                'product_id' => $this->product->id,
                'qty' => '10',
                'value' => '1000',
            ]);
    }

    private function aStranger(): User
    {
        $person = User::factory()->create(['is_active' => true]);
        $person->companies()->attach($this->company->id, ['is_active' => true]);
        $person->forceFill(['current_company_id' => $this->company->id])->save();
        $person->givePermissionTo([Permission::findOrCreate('promotion.view', 'web')]);

        return $person;
    }

    /** ⭐ একই মানুষ: `coupon` চাবি ছাড়া পাতা আর তৈরি দুইটাই বন্ধ, চাবি পেলে খোলে আর কোড জন্মায়। */
    public function test_the_same_person_needs_the_coupon_key(): void
    {
        $clerk = $this->aStranger();
        $clerk->givePermissionTo(Permission::findOrCreate('promotion.apply', 'web'));

        $this->actingAs($clerk)
            ->get(route('promotion.coupon.index', ['offer' => $this->offer->id]))
            ->assertForbidden();

        $this->issueThrough(['count' => 3], $clerk)->assertForbidden();

        $this->assertSame(0, PromotionCoupon::query()->where('promotion_id', $this->offer->id)->count(),
            'চাবি ছাড়া দরজা বন্ধ বলল, অথচ কোড জন্মে গেল।');

        $clerk->givePermissionTo(Permission::findOrCreate('promotion.coupon', 'web'));
        $clerk = $clerk->fresh();

        $this->actingAs($clerk)
            ->get(route('promotion.coupon.index', ['offer' => $this->offer->id]))
            ->assertOk();

        $this->issueThrough(['count' => 3], $clerk)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('promotion.coupon.index', ['offer' => $this->offer->id]));

        $this->assertSame(3, PromotionCoupon::query()->where('promotion_id', $this->offer->id)->count(),
            'চাবি পাওয়ার পরেও তিনটা কোড জন্মায়নি — তাহলে ৪০৩-টা চাবির জন্য ছিল না।');
    }

    /** ⭐ একটা লেখা কোড — ঠিক ঐ রূপেই জন্মায় (বড় হাতের, ফাঁকা ছাড়া), একটাই। */
    public function test_a_typed_code_is_born_once_and_in_its_clean_shape(): void
    {
        $this->issueThrough(['code' => ' eid 100 ', 'count' => 1])->assertSessionHasNoErrors();

        $this->assertSame(['EID100'], PromotionCoupon::query()
            ->where('promotion_id', $this->offer->id)->pluck('code')->all());

        /* ⛔ একই কোড দ্বিতীয়বার — ডেস্কের কারণসহ ফিরে আসে, নতুন সারি নয় */
        $this->issueThrough(['code' => 'EID100', 'count' => 1])->assertSessionHasErrors('code');

        $this->assertSame(1, PromotionCoupon::query()->where('code', 'EID100')->count());
    }

    /** ⛔ শেষ হয়ে যাওয়া অফারে নতুন কোড নয় — কিন্তু চলতি অফারে হয় (পাল্টা-দাবি উপরে)। */
    public function test_a_finished_offer_gets_no_new_codes(): void
    {
        $this->offer->status = PromotionStatus::CANCELLED;
        $this->offer->save();

        $this->issueThrough(['count' => 2])->assertSessionHasErrors('promotion');

        $this->assertSame(0, PromotionCoupon::query()->where('promotion_id', $this->offer->id)->count());
    }

    /** ⭐ পাতায় নিজের অফারের কোড দেখায়, অন্য অফারেরটা নয় — আর সব-অফারের পাতায় দুইটাই। */
    public function test_the_page_shows_the_offers_own_codes(): void
    {
        $desk = app(CouponDesk::class);
        $desk->issue($this->offer, 1, 'SCREEN-OWN');
        $desk->issue($this->aCouponOffer('PROM-CS-0002'), 1, 'SCREEN-OTHER');

        $this->get(route('promotion.coupon.index', ['offer' => $this->offer->id]))
            ->assertOk()
            ->assertSee('SCREEN-OWN')
            ->assertDontSee('SCREEN-OTHER');

        $this->get(route('promotion.coupon.index'))
            ->assertOk()
            ->assertSee('SCREEN-OWN')
            ->assertSee('SCREEN-OTHER');
    }

    /**
     * ⛔ ভুল কোড — ৪২২ JSON, ৩০২ নয়।
     *
     * ⚠️ বিপজ্জনক ইনপুট: কোনো কুপনে নেই এমন কোড, `Accept` ছাড়া। ⓘ ৩০২ এলে
     * fetch রিডাইরেক্ট মেনে ২০০ HTML পেত, আর কাউন্টার ধরে নিত *"বসেছে"*।
     */
    public function test_a_wrong_code_gets_a_json_refusal_not_a_redirect(): void
    {
        app(CouponDesk::class)->issue($this->offer, 1, 'RIGHT-CODE');

        $this->redeemAtCounter('WRONG-CODE')
            ->assertStatus(422)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonStructure(['message', 'errors' => ['coupon']]);

        $this->assertSame(0, PromotionCoupon::query()->where('code', 'RIGHT-CODE')->value('used_count'));

        /* ⭐ পাল্টা-দাবি: ঠিক কোড একই দরজায় খাটে, আর ব্যবহার একবার গোনা হয় */
        $this->redeemAtCounter('right-code')
            ->assertOk()
            ->assertJsonPath('data.code', 'RIGHT-CODE')
            ->assertJsonPath('data.promotion', 'PROM-CS-0001')
            ->assertJsonPath('data.uses_left', 0);

        $this->assertSame(1, PromotionCoupon::query()->where('code', 'RIGHT-CODE')->value('used_count'));
    }

    /**
     * ⛔ ভাঙা প্রশ্ন — `1e5` পরিমাণ, আর তালিকার বাইরের কাগজ — দুইটাই ৪২২ JSON।
     *
     * ⓘ `1e5` `numeric` পেরোয় কিন্তু bcmath-এ ভাঙে; ⚠️ অচেনা `source_type`
     * দিয়ে কেউ এমন "বিলে" কোড খাটাতেন যেটা কোনো তালিকায় আসে না।
     */
    public function test_a_broken_question_is_refused_as_json_and_counts_nothing(): void
    {
        app(CouponDesk::class)->issue($this->offer, 1, 'SAFE-CODE');

        $this->redeemAtCounter('SAFE-CODE', ['qty' => '1e5'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['qty']]);

        $this->redeemAtCounter('SAFE-CODE', ['source_type' => 'anything_at_all'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['source_type']]);

        $this->assertSame(0, PromotionCoupon::query()->where('code', 'SAFE-CODE')->value('used_count'),
            'ভাঙা প্রশ্নেও কুপনের ব্যবহার গোনা হয়ে গেল।');
    }

    /** ⭐ একই মানুষ: `apply` চাবি ছাড়া কাউন্টারের দরজা বন্ধ, চাবি পেলে খোলে। */
    public function test_the_counter_door_needs_the_apply_key(): void
    {
        app(CouponDesk::class)->issue($this->offer, 1, 'COUNTER-KEY');

        $cashier = $this->aStranger();

        $this->redeemAtCounter('COUNTER-KEY', [], $cashier)->assertForbidden();
        $this->assertSame(0, PromotionCoupon::query()->where('code', 'COUNTER-KEY')->value('used_count'));

        $cashier->givePermissionTo(Permission::findOrCreate('promotion.apply', 'web'));

        $this->redeemAtCounter('COUNTER-KEY', [], $cashier->fresh())->assertOk();
        $this->assertSame(1, PromotionCoupon::query()->where('code', 'COUNTER-KEY')->value('used_count'),
            'চাবি পাওয়ার পরেও কোড খাটেনি — তাহলে ৪০৩-টা চাবির জন্য ছিল না।');
    }
}
