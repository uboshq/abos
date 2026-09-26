<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Services\PromotionEngine;
use App\Modules\Promotion\Services\PromotionRules;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\ConditionKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ইঞ্জিন আরও চারটা ধরন চিনত, অথচ কেউ সেগুলো বাছতে পারতেন না — স্পেক §৭।
 *
 * ── ⓘ কী মাপা হলো ──────────────────────────────────────────────────
 * [[PromotionEngine]] ধরন দেখে না, কেবল শর্ত আর সুবিধা দেখে। ⚠️ তাই শতাংশ
 * ছাড়, নির্দিষ্ট ছাড়, *"X কিনলে ছাড়"* আর ফ্রি পণ্য — চারটাই সে আগে
 * থেকেই হিসাব করতে পারত; কেবল [[PromotionType::isBuilt()]] দরজা বন্ধ
 * রেখেছিল। ⭐ প্রতিটা ধরনের জন্য ইঞ্জিনের আসল উত্তর মাপা হয় — দরজা
 * খোলা শুধু *"বাছা যায়"* প্রমাণ করে, *"ঠিক হিসাব করে"* নয়।
 *
 * ⛔ আর ধরন ও সুবিধা মেলাতে হয়: *"ফ্রি পণ্য"* অফারে ২০% ছাড় বসলে অফারের
 * নাম এক কথা বলত, বিলে বসত আরেক কথা।
 */
final class TheEngineKnewFourMoreTypesAndNobodyCouldPickThemTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    private function anOffer(PromotionType $type, PromotionStatus $status = PromotionStatus::ACTIVE): Promotion
    {
        $offer = new Promotion([
            'name_en' => $type->value,
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = 'PROM-T-'.strtoupper(substr(md5($type->value.$status->value), 0, 6));
        $offer->type = $type;
        $offer->status = $status;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        return $offer;
    }

    private function step(Promotion $offer, BenefitKind $kind, string $amount, ?string $from = null, ?int $gift = null): void
    {
        $condition = PromotionCondition::query()->create([
            'promotion_id' => $offer->id,
            'kind' => ConditionKind::QUANTITY,
            'value_from' => $from,
        ]);

        PromotionBenefit::query()->create([
            'promotion_id' => $offer->id,
            'promotion_condition_id' => $condition->id,
            'kind' => $kind,
            'amount' => $amount,
            'gift_product_id' => $gift,
        ]);
    }

    /** @return array<string, mixed> */
    private function line(string $qty, string $value): array
    {
        return ['product_id' => $this->product->id, 'qty' => $qty, 'value' => $value];
    }

    private function worthFor(Promotion $offer, array $line): ?string
    {
        $row = app(PromotionEngine::class)->offersFor($line)
            ->first(fn (array $r) => $r['promotion']->id === $offer->id);

        return $row === null ? null : $row['worth'];
    }

    /** ⭐ চারটাই এখন বাছা যায় — আর যেগুলো ইঞ্জিন চেনে না সেগুলো এখনো নয়। */
    public function test_the_four_types_can_be_picked_and_the_unknown_ones_still_cannot(): void
    {
        foreach ([PromotionType::PERCENT_DISCOUNT, PromotionType::FIXED_DISCOUNT,
            PromotionType::BUY_X_GET_DISCOUNT, PromotionType::FREE_PRODUCT, PromotionType::COUPON] as $type) {
            $this->assertContains($type, PromotionType::built(), $type->value.' এখনো বাছা যায় না।');
        }

        /* ⓘ কম্বো/বান্ডেল বিলের জোড়ার পরে, লয়্যালটি পয়েন্ট খরচের পথ হলে, ক্যাশব্যাক হিসাব মডিউলের সাথে */
        foreach ([PromotionType::COMBO, PromotionType::BUNDLE,
            PromotionType::CASHBACK, PromotionType::LOYALTY] as $type) {
            $this->assertNotContains($type, PromotionType::built(),
                $type->value.' বাছা যায়, অথচ ইঞ্জিন এটা হিসাব করে না।');
        }
    }

    /** ⭐ শতাংশ ছাড়: ১০% × ৫,০০০ = ৫০০। */
    public function test_a_percent_discount_takes_its_share_of_the_line(): void
    {
        $offer = $this->anOffer(PromotionType::PERCENT_DISCOUNT);
        $this->step($offer, BenefitKind::PERCENT, '10');

        $this->assertSame(0, bccomp((string) $this->worthFor($offer, $this->line('5', '5000')), '500', 4));
    }

    /** ⭐ নির্দিষ্ট ছাড়: লাইন যত বড়ই হোক, ৫০ টাকাই। */
    public function test_a_fixed_discount_is_the_same_on_any_line(): void
    {
        $offer = $this->anOffer(PromotionType::FIXED_DISCOUNT);
        $this->step($offer, BenefitKind::AMOUNT, '50');

        $this->assertSame(0, bccomp((string) $this->worthFor($offer, $this->line('1', '100')), '50', 4));
        $this->assertSame(0, bccomp((string) $this->worthFor($offer, $this->line('90', '90000')), '50', 4));
    }

    /**
     * ⭐ X কিনলে ছাড়: ১০ কার্টনে খোলে, ৯-এ নয়।
     *
     * ⚠️ বিপজ্জনক ইনপুট: ঠিক সীমানার এক কম। ⓘ পাল্টা-দাবি সীমানায় ঠিক
     * সমান — নাহলে *"সবসময় বন্ধ"* লিখেও প্রথম অর্ধেকটা সবুজ হত।
     */
    public function test_buy_x_get_a_discount_opens_at_x_and_not_before(): void
    {
        $offer = $this->anOffer(PromotionType::BUY_X_GET_DISCOUNT);
        $this->step($offer, BenefitKind::PERCENT, '5', '10');

        $this->assertNull($this->worthFor($offer, $this->line('9', '9000')), '৯ কার্টনেই ছাড় খুলে গেছে।');
        $this->assertSame(0, bccomp((string) $this->worthFor($offer, $this->line('10', '10000')), '500', 4));
    }

    /** ⭐ ফ্রি পণ্য: উপহারটা বিলে ওঠে, আর তার দাম বিক্রয়মূল্যে গোনা হয়। */
    public function test_a_free_product_offer_is_worth_its_gift(): void
    {
        $offer = $this->anOffer(PromotionType::FREE_PRODUCT);
        $this->step($offer, BenefitKind::GOODS, '2', null, $this->product->id);

        $expected = bcmul('2', (string) ($this->product->sale_price ?? '0'), 4);

        $this->assertSame(0, bccomp((string) $this->worthFor($offer, $this->line('1', '100')), $expected, 4));
    }

    /**
     * ⛔ ধরন আর সুবিধা মিলতে হয় — *"ফ্রি পণ্য"* অফারে শতাংশ ছাড় বসে না।
     *
     * ⓘ পাল্টা-দাবি: একই অফারে উপহারের ধাপ ঠিকই বসে।
     */
    public function test_a_free_product_offer_refuses_a_percent_step(): void
    {
        $offer = $this->anOffer(PromotionType::FREE_PRODUCT, PromotionStatus::DRAFT);
        $rules = app(PromotionRules::class);

        try {
            $rules->addStep($offer, ['condition_kind' => 'quantity', 'benefit_kind' => 'percent', 'amount' => '20']);
            $this->fail('ফ্রি পণ্যের অফারে ২০% ছাড়ের ধাপ বসে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('benefit_kind', $e->errors());
        }

        $rules->addStep($offer, [
            'condition_kind' => 'quantity', 'benefit_kind' => 'goods', 'amount' => '1',
            'gift_product_id' => $this->product->id,
        ]);

        $this->assertSame(1, $offer->conditions()->count());
    }
}
