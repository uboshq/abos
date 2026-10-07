<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * সইয়ের পরে নিয়ম বদলে যেত।
 *
 * ── ⛔ কেন নিয়ম কেবল খসড়ায় বদলায় ─────────────────────────────────
 * ⓘ অনুমোদনের মানে: *"এই নিয়মগুলো দ্বিতীয় একজন দেখেছেন"*। ⚠️ পরে
 * একটা ধাপ বদলানো গেলে অনুমোদনকারী যা দেখেছিলেন আর যা চলছে — দুইটা
 * আলাদা হত, অথচ কাগজে সই থেকেই যেত।
 */
final class TheRulesChangedAfterTheSignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function anOffer(PromotionStatus $status): Promotion
    {
        $offer = new Promotion([
            'name_en' => 'Rules',
            'name_bn' => 'নিয়ম',
            'starts_on' => Carbon::today(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = 'PROM-N-'.$status->value;
        $offer->type = PromotionType::QUANTITY_SLAB;
        $offer->status = $status;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        return $offer;
    }

    /** @return array<string, string> */
    private function aStep(array $overrides = []): array
    {
        return array_merge([
            'condition_kind' => 'quantity',
            'value_from' => '50',
            'value_to' => '99',
            'benefit_kind' => 'percent',
            'amount' => '5',
        ], $overrides);
    }

    /** ⭐ খসড়ায় ধাপ বসে — পাল্টা-দাবি, নাহলে "সব আটকাও" লিখেও নিচেরগুলো সবুজ হত। */
    public function test_a_draft_takes_a_step(): void
    {
        $offer = $this->anOffer(PromotionStatus::DRAFT);

        $this->actingAs($this->owner)
            ->post(route('promotion.step.store', $offer), $this->aStep())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PromotionCondition::query()->where('promotion_id', $offer->id)->count());
    }

    /**
     * ⛔ চলতি অফারে ধাপ বসে না।
     *
     * ⚠️ বিপজ্জনক ইনপুট: `promotion.update` চাবিওয়ালা মানুষ, চলতি অফার।
     * ⓘ বসে গেলে একই দিনের দুইটা বিল দুই নিয়মে কাটা হত, অনুমোদন ছাড়াই।
     */
    public function test_a_running_offer_takes_no_new_step(): void
    {
        $offer = $this->anOffer(PromotionStatus::ACTIVE);

        $this->actingAs($this->owner)
            ->post(route('promotion.step.store', $offer), $this->aStep())
            ->assertSessionHasErrors('status');

        $this->assertSame(0, PromotionCondition::query()->where('promotion_id', $offer->id)->count(),
            'চলতি অফারে অনুমোদন ছাড়াই নতুন ধাপ বসে গেছে।');
    }

    /**
     * ⛔ উপহারের ধাপে পণ্য না দিলে বসে না।
     *
     * ⓘ বসলে ইঞ্জিন ধাপটা চুপচাপ বাদ দিত — মানুষ ভাবতেন অফার চলছে, আর
     * ক্রেতা উপহারটা কোনোদিন পেতেন না।
     */
    public function test_a_gift_step_without_a_product_is_refused(): void
    {
        $offer = $this->anOffer(PromotionStatus::DRAFT);

        $this->actingAs($this->owner)
            ->post(route('promotion.step.store', $offer), $this->aStep(['benefit_kind' => 'goods', 'amount' => '5']))
            ->assertSessionHasErrors('gift_product_id');
    }

    /** ⛔ উল্টো পরিসর — ৯৯ থেকে ৫০ — কোনো সংখ্যাকেই ধরত না। */
    public function test_an_upside_down_range_is_refused(): void
    {
        $offer = $this->anOffer(PromotionStatus::DRAFT);

        $this->actingAs($this->owner)
            ->post(route('promotion.step.store', $offer), $this->aStep(['value_from' => '99', 'value_to' => '50']))
            ->assertSessionHasErrors('value_to');
    }

    /**
     * ⛔ ১০০%-এর বেশি ছাড় নয়।
     *
     * ⓘ ১৫০% মানে বিলের চেয়ে বেশি ফেরত — ক্রেতাকে টাকা দিয়ে মাল বিক্রি।
     * ⚠️ এটা সবসময় একটা আঙুলের ভুল, আর এক বিলেই গোটা দিনের লাভ যেত।
     */
    public function test_a_discount_above_a_hundred_percent_is_refused(): void
    {
        $offer = $this->anOffer(PromotionStatus::DRAFT);

        $this->actingAs($this->owner)
            ->post(route('promotion.step.store', $offer), $this->aStep(['amount' => '150']))
            ->assertSessionHasErrors('amount');
    }

    /** ⭐ অফারের পাতাটা সত্যিই খোলে — ব্লেডের অনুবাদ বৈধ কি না, এটাই একমাত্র প্রমাণ। */
    public function test_the_offer_page_actually_opens(): void
    {
        $offer = $this->anOffer(PromotionStatus::DRAFT);

        $this->actingAs($this->owner)
            ->get(route('promotion.show', $offer))
            ->assertOk()
            ->assertSee($offer->code);
    }
}
