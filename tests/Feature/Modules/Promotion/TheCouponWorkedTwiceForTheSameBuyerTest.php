<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCoupon;
use App\Modules\Promotion\Models\PromotionCouponRedemption;
use App\Modules\Promotion\Services\CouponDesk;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই ক্রেতার হাতে কুপনটা দুইবার খাটল — স্পেক §৭-ঞ।
 *
 * ── ⚠️ কী ঘটত [[CouponDesk]] ছাড়া ───────────────────────────────────
 * ⓘ কুপনের কোডটা কেবল একটা নাম হত, আর গোনা হত না। ⛔ একবারের কুপন
 * দশবার খাটত, একজনের জন্য বানানো কুপন অন্যজন নিতেন, আর বিল বাতিল
 * করে আবার কাটলে একটা ব্যবহার দুইবার ফেরত আসত। ⚠️ কোথাও কিছু লাল
 * হত না — প্রতিটা বিল নিজে নিজে ঠিক দেখাত।
 *
 * ── ⭐ প্রতিটা দাবি বিপজ্জনক ইনপুট খায়, আর পাশে একটা পাল্টা-দাবি ─────
 * ⓘ প্রত্যাখ্যানটা কেবল *"থামল"* দেখে সবুজ হয় না — একই দরজা একই
 * সময়ে অন্য ইনপুটে **খোলে**, তাই থামাটা নিয়মের জন্য, ভাঙা দরজার জন্য নয়।
 */
final class TheCouponWorkedTwiceForTheSameBuyerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Product $product;

    private Customer $rahim;

    private Customer $karim;

    private Promotion $offer;

    private int $bill = 9100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->product = Product::query()->orderBy('id')->firstOrFail();

        $buyers = Customer::query()->orderBy('id')->take(2)->get();
        $this->assertCount(2, $buyers, 'ডেমোতে দুইজন ক্রেতা নেই — ক্রেতা-প্রতি দাবিগুলো কিছু মাপত না।');
        [$this->rahim, $this->karim] = [$buyers[0], $buyers[1]];

        $this->offer = $this->aCouponOffer('PROM-C-0001');
    }

    private function aCouponOffer(string $code): Promotion
    {
        $offer = new Promotion([
            'name_en' => 'Coupon '.$code,
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

    /** @param  array<string, mixed>  $terms */
    private function aCoupon(string $code, array $terms = []): PromotionCoupon
    {
        return $this->desk()->issue($this->offer, 1, $code, $terms)->first();
    }

    private function redeem(string $code, ?Customer $buyer): PromotionApplication
    {
        $this->bill++;

        return $this->desk()->redeem(
            $code,
            [
                'product_id' => $this->product->id,
                'qty' => '10',
                'value' => '1000',
                'customer_id' => $buyer?->id,
            ],
            'sales_invoice',
            $this->bill,
        );
    }

    /** ⓘ ফেরত দেয় বার্তাটা — থামাটা **কুপনের** ঘরে হয়েছে কি না, সেটাও দেখে */
    private function refused(callable $attempt): string
    {
        try {
            $attempt();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('coupon', $e->errors(), 'থামল, কিন্তু কুপনের কারণে নয়: '.json_encode($e->errors()));

            return $e->errors()['coupon'][0];
        }

        $this->fail('কুপনটা খেটে গেল, অথচ থামার কথা ছিল।');
    }

    private function desk(): CouponDesk
    {
        return app(CouponDesk::class);
    }

    /**
     * ⛔ একবারের কুপন দ্বিতীয়বার খাটে না — ক্রেতা বদলালেও।
     *
     * ⚠️ বিপজ্জনক ইনপুট: দ্বিতীয় বিলটা **অন্য** ক্রেতার, আর কোডটা ছোট
     * হাতে টাইপ করা। ⓘ ক্রেতা-প্রতি গুনলে বা হুবহু কোড মেলালে এটা ফসকাত।
     */
    public function test_a_coupon_stops_at_its_total_uses(): void
    {
        $this->aCoupon('EID-ONE', ['max_uses' => 1]);

        $first = $this->redeem('EID-ONE', $this->rahim);
        $this->assertSame(0, bccomp((string) $first->worth, '100', 4));

        $this->refused(fn () => $this->redeem('eid-one', $this->karim));

        $coupon = PromotionCoupon::query()->where('code', 'EID-ONE')->firstOrFail();
        $this->assertSame(1, $coupon->used_count, 'থামা ব্যবহারটাও গোনা হয়ে গেছে।');
        $this->assertSame(1, PromotionCouponRedemption::query()->where('coupon_id', $coupon->id)->count());
        $this->assertSame(0, PromotionApplication::query()->where('source_id', $this->bill)->count(),
            'কুপন থামল, অথচ বিলে সুবিধাটা বসে গেছে।');

        /* ⭐ পাল্টা-দাবি: দুইবারের কুপন দ্বিতীয়বার খোলে */
        $this->aCoupon('EID-TWO', ['max_uses' => 2]);
        $this->redeem('EID-TWO', $this->rahim);
        $this->redeem('EID-TWO', $this->karim);
        $this->refused(fn () => $this->redeem('EID-TWO', $this->rahim));
    }

    /**
     * ⛔ একজন ক্রেতা নিজের সীমার বেশি নিতে পারেন না — অথচ অন্যজন পারেন।
     *
     * ⚠️ বিপজ্জনক ইনপুট: মোট সীমায় অনেক জায়গা বাকি (৫), কেবল ক্রেতার
     * সীমা (১) শেষ। ⓘ কেবল মোট দেখলে রহিম পাঁচবারই নিতেন।
     */
    public function test_one_buyer_stops_at_their_own_limit(): void
    {
        $this->aCoupon('RAMADAN', ['max_uses' => 5, 'max_uses_per_customer' => 1]);

        $this->redeem('RAMADAN', $this->rahim);
        $this->refused(fn () => $this->redeem('RAMADAN', $this->rahim));

        /* ⭐ পাল্টা-দাবি: করিম এখনো পান */
        $this->redeem('RAMADAN', $this->karim);

        /* ⛔ আর নাম ছাড়া নগদ বিলে সীমাটা এড়ানো যায় না */
        $this->refused(fn () => $this->redeem('RAMADAN', null));

        $this->assertSame(2, PromotionCoupon::query()->where('code', 'RAMADAN')->value('used_count'));
    }

    /** ⛔ একজনের জন্য বানানো কুপন অন্যজনের হাতে খাটে না, নাম-ছাড়া বিলেও না। */
    public function test_a_bound_coupon_works_only_for_its_buyer(): void
    {
        $this->aCoupon('FOR-RAHIM', ['max_uses' => 3, 'customer_id' => $this->rahim->id]);

        $this->refused(fn () => $this->redeem('FOR-RAHIM', $this->karim));
        $this->refused(fn () => $this->redeem('FOR-RAHIM', null));

        /* ⭐ পাল্টা-দাবি: রহিমের হাতে খোলে */
        $this->redeem('FOR-RAHIM', $this->rahim);

        $this->assertSame(1, PromotionCoupon::query()->where('code', 'FOR-RAHIM')->value('used_count'));
    }

    /**
     * ⭐ বিল বাতিলে একটা ব্যবহার ফেরে — **ঠিক একবার**।
     *
     * ⚠️ বিপজ্জনক ইনপুট: বাতিলের দরজা দুইবার চাপা। ⛔ দুইবার ফেরালে
     * একবারের কুপন আবার দুইবার খাটত — তাই শেষে তৃতীয় বিলটা থামতে হবে।
     */
    public function test_a_cancelled_bill_frees_the_use_exactly_once(): void
    {
        $this->aCoupon('ONCE', ['max_uses' => 1]);

        $this->redeem('ONCE', $this->rahim);
        $cancelled = $this->bill;

        $this->assertSame(1, $this->desk()->release('sales_invoice', $cancelled));
        $this->assertSame(0, $this->desk()->release('sales_invoice', $cancelled),
            'একই বাতিল দ্বিতীয়বার আরেকটা ব্যবহার ফেরাল।');

        $coupon = PromotionCoupon::query()->where('code', 'ONCE')->firstOrFail();
        $this->assertSame(0, $coupon->used_count);
        $this->assertNotNull(PromotionCouponRedemption::query()
            ->where('source_id', $cancelled)->value('reversed_at'));

        /* ⭐ ফেরত ব্যবহারটা আবার খাটে … */
        $this->redeem('ONCE', $this->karim);

        /* ⛔ … কিন্তু কেবল একবার */
        $this->refused(fn () => $this->redeem('ONCE', $this->rahim));
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    /**
     * ⛔ অন্য কোম্পানির কোড এখানে অদৃশ্য — আর বার্তাটা না-থাকা কোডের মতোই।
     *
     * ⚠️ বার্তা আলাদা হলে কেউ বাইরে থেকে অন্য কোম্পানির কোড আঁচ করতে
     * পারতেন। ⓘ আর অনন্যতা কোম্পানি-প্রতি: অন্য কোম্পানি একই কোড নিজের
     * জন্য বানাতে পারে।
     */
    public function test_another_companys_code_is_not_found(): void
    {
        $this->aCoupon('EID100', ['max_uses' => 10]);

        $mart = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($mart->id, $mart->defaultBranch()?->id);

        $message = $this->refused(fn () => $this->desk()->redeem(
            'EID100',
            ['product_id' => $this->product->id, 'qty' => '10', 'value' => '1000'],
            'sales_invoice',
            9901,
        ));

        $this->assertSame(__('promotion::coupon.not_found', ['code' => 'EID100']), $message);

        $theirs = PromotionCoupon::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', 'EID100')->firstOrFail();
        $this->assertSame(0, $theirs->used_count, 'অন্য কোম্পানি থেকে এই কোম্পানির কুপন খরচ হয়ে গেছে।');

        /* ⭐ পাল্টা-দাবি: FMART নিজের EID100 বানাতে পারে */
        $this->offer = $this->aCouponOffer('PROM-C-0002');
        $own = $this->aCoupon('EID100', ['max_uses' => 1]);
        $this->assertSame($mart->id, (int) $own->company_id);
    }
}
