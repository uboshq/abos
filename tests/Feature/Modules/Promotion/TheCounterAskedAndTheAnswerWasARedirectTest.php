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
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\ConditionKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * কাউন্টার প্রশ্ন করত, আর উত্তর আসত একটা রিডাইরেক্ট।
 *
 * ── ⚠️ এই ফাইলের নামটাই একটা সতর্কতা ────────────────────────────────
 * ⓘ ২৫ সেপ্টেম্বর কাউন্টারের ফ্রি-সীমার দরজায় ধরা পড়েছিল: `api/*`-এর
 * বাইরে `validate()` ব্যর্থ হলে উত্তর ৩০২, ৪২২ নয়। ⛔ fetch ২০০ HTML
 * পেত, `json()` ছুঁড়ত, আর পর্দা নীরবে ধরে নিত *"কিছু নেই"*।
 *
 * ⭐ তাই এখানে প্রতিটা ভুল উত্তরের **অবস্থা-সংখ্যাই** মাপা হয়।
 */
final class TheCounterAskedAndTheAnswerWasARedirectTest extends TestCase
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
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        $offer = new Promotion([
            'name_en' => 'Buy 100',
            'name_bn' => '১০০ কিনলে',
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = 'PROM-S-0001';
        $offer->type = PromotionType::QUANTITY_SLAB;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        $step = PromotionCondition::query()->create([
            'promotion_id' => $offer->id,
            'kind' => ConditionKind::QUANTITY,
            'value_from' => '100',
        ]);

        PromotionBenefit::query()->create([
            'promotion_id' => $offer->id,
            'promotion_condition_id' => $step->id,
            'kind' => BenefitKind::PERCENT,
            'amount' => '5',
        ]);
    }

    /** ⭐ যোগ্য সারিতে অফারটা আসে, আর "প্রায়" সারিতে ঘাটতিটা। */
    public function test_the_counter_hears_what_fits_and_what_almost_does(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('promotion.suggest', ['product_id' => $this->product->id, 'qty' => '100', 'value' => '10000']))
            ->assertOk()
            ->assertJsonPath('data.eligible.0.code', 'PROM-S-0001');

        $this->actingAs($this->owner)
            ->getJson(route('promotion.suggest', ['product_id' => $this->product->id, 'qty' => '92', 'value' => '9200']))
            ->assertOk()
            ->assertJsonCount(0, 'data.eligible')
            ->assertJsonPath('data.almost.0.short_by', '8.0000');
    }

    /**
     * ⛔ ভুল ইনপুটে উত্তর ৪২২ — ৩০২ নয়।
     *
     * ⚠️ বিপজ্জনক ইনপুটটাই এটা: শূন্য পরিমাণ। ⓘ `validate()` দিয়ে লিখলে
     * এখানে ৩০২ আসত, আর কাউন্টার নীরবে ধরে নিত *"কোনো অফার নেই"*।
     */
    public function test_a_bad_question_gets_a_json_refusal_not_a_redirect(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('promotion.suggest', ['product_id' => $this->product->id, 'qty' => '0', 'value' => '0']))
            ->assertStatus(422);
    }

    /**
     * ⛔ অন্য কোম্পানির পণ্য — ৪২২, অফারের খবর নয়।
     *
     * ⓘ `Rule::exists` গ্লোবাল স্কোপ মানে না। ⚠️ তাই অন্য কোম্পানির
     * পণ্যও *"আছে"* পেত, আর উত্তরে ঐ কোম্পানির অফারের খবর চলে যেতে
     * পারত।
     */
    public function test_another_companys_product_is_refused(): void
    {
        $theirs = Company::query()->where('id', '!=', CompanyContext::id())->firstOrFail();

        /* ⓘ `DB::table()` — গ্লোবাল স্কোপ ছাড়া, কোম্পানি না বদলেই অন্যের সারি বসানো */
        $foreign = DB::table('inv_products')->insertGetId([
            'company_id' => $theirs->id,
            'public_id' => (string) \Illuminate\Support\Str::uuid7(),
            'code' => 'FOREIGN-P',
            'name_en' => 'Their product',
            'name_bn' => 'ওদের পণ্য',
            'unit_id' => $this->product->unit_id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->getJson(route('promotion.suggest', ['product_id' => $foreign, 'qty' => '100', 'value' => '10000']))
            ->assertStatus(422);
    }

    /** ⛔ `promotion.apply` চাবি ছাড়া কেউ অফার খুঁজতে পারেন না। */
    public function test_without_the_apply_key_the_counter_cannot_ask(): void
    {
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $stranger = User::factory()->create(['is_active' => true]);
        $stranger->companies()->attach($company->id, ['is_active' => true]);
        $stranger->switchCompany($company->id);

        $this->actingAs($stranger)
            ->getJson(route('promotion.suggest', ['product_id' => $this->product->id, 'qty' => '100', 'value' => '10000']))
            ->assertForbidden();
    }
}
