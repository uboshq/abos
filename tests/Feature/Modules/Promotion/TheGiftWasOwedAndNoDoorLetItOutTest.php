<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
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
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * উপহার পাওনা ছিল, অথচ কোনো দরজা মালটা বের করতে দিত না — স্পেক §৮।
 *
 * ── ⚠️ কী ঘটছিল ────────────────────────────────────────────────────
 * ⓘ [[GiftIssuer]] ছিল, কিন্তু কেবল পরীক্ষা তাকে ডাকত। ⛔ আর সে দেখত
 * না উপহারটা আগে দেওয়া হয়েছে কি না — একই পাঁচ কার্টন দুইবার বেরোতে
 * পারত, বাতিল বিলের উপহারও।
 *
 * ⓘ মজুদ গোনা হয় [[StockService::floorQty()]] থেকে, উপহারের কাগজ থেকে নয়।
 */
final class TheGiftWasOwedAndNoDoorLetItOutTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Warehouse $warehouse;

    private Product $gift;

    private PromotionApplication $owed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->warehouse = Warehouse::query()->create([
            'code' => 'GDOOR', 'name_en' => 'Gift door', 'name_bn' => 'উপহারের দরজা', 'is_active' => true,
        ]);

        $this->gift = Product::query()->orderBy('id')->firstOrFail();
        $this->gift->track_batch = false;
        $this->gift->save();

        $stock = app(StockService::class);
        $stock->move(product: $this->gift, warehouse: $this->warehouse,
            sourceType: 'purchase_bill', sourceId: 5301, unplaced: '100');
        $stock->place(product: $this->gift, warehouse: $this->warehouse,
            qty: '100', sourceType: 'purchase_bill', sourceId: 5301);

        $offer = new Promotion([
            'name_en' => 'Door gift',
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = 'PROM-D-0001';
        $offer->type = PromotionType::BUY_X_GET_Y;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        $step = PromotionCondition::query()->create([
            'promotion_id' => $offer->id,
            'kind' => ConditionKind::QUANTITY,
            'value_from' => '100',
        ]);

        $benefit = PromotionBenefit::query()->create([
            'promotion_id' => $offer->id,
            'promotion_condition_id' => $step->id,
            'kind' => BenefitKind::GOODS,
            'amount' => '5',
            'gift_product_id' => $this->gift->id,
            'gift_unit_id' => $this->gift->unit_id,
        ]);

        $this->owed = PromotionApplication::query()->create([
            'promotion_id' => $offer->id,
            'promotion_benefit_id' => $benefit->id,
            'source_type' => 'sales_invoice',
            'source_id' => 7401,
            'benefit_kind' => BenefitKind::GOODS,
            'benefit_amount' => '5',
            'worth' => '500',
        ]);
    }

    private function floor(): string
    {
        return app(StockService::class)->floorQty($this->gift, $this->warehouse);
    }

    private function issue(string $qty, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)
            ->post(route('promotion.gift.store', $this->owed), [
                'warehouse_id' => $this->warehouse->id,
                'qty' => $qty,
            ]);
    }

    /** ⭐ পাতায় পাওনা উপহারটা দেখা যায়, আর দরজা দিয়ে বেরোলে মেঝে কমে। */
    public function test_the_owed_gift_is_listed_and_leaves_through_the_door(): void
    {
        $this->actingAs($this->owner)
            ->get(route('promotion.gift.index'))
            ->assertOk()
            ->assertSee('PROM-D-0001');

        $before = $this->floor();
        $this->issue('5')->assertSessionHasNoErrors();

        $this->assertSame(0, bccomp(bcsub($before, $this->floor(), 4), '5', 4),
            'দরজা দিয়ে উপহার বেরোল, অথচ মেঝে পাঁচ কমেনি।');

        /* ⓘ পুরো দেওয়ার পরে তালিকা থেকে সরে যায় */
        $this->actingAs($this->owner)
            ->get(route('promotion.gift.index'))
            ->assertOk()
            ->assertDontSee('PROM-D-0001');
    }

    /**
     * ⛔ একই উপহার দুইবার বেরোয় না।
     *
     * ⚠️ বিপজ্জনক ইনপুট: পাঁচ পাওনা, পাঁচ দেওয়ার পরে আবার পাঁচ। ⓘ ধরা না
     * পড়লে গুদামের দুইজন একই তালিকা দেখে দুইবার দিতেন।
     */
    public function test_the_same_gift_does_not_leave_twice(): void
    {
        $before = $this->floor();

        $this->issue('5')->assertSessionHasNoErrors();
        $this->issue('5')->assertSessionHasErrors('qty');

        $this->assertSame(0, bccomp(bcsub($before, $this->floor(), 4), '5', 4),
            'পাওনার চেয়ে বেশি উপহার বেরিয়ে গেছে।');
    }

    /** ⭐ পাল্টা-দাবি: ভাগে ভাগে দেওয়া চলে — ৩ তারপর ২। */
    public function test_a_gift_can_leave_in_parts(): void
    {
        $before = $this->floor();

        $this->issue('3')->assertSessionHasNoErrors();
        $this->issue('2')->assertSessionHasNoErrors();

        $this->assertSame(0, bccomp(bcsub($before, $this->floor(), 4), '5', 4));
    }

    /** ⛔ বাতিল বিলের উপহার বেরোয় না। */
    public function test_a_cancelled_bills_gift_does_not_leave(): void
    {
        $this->owed->reversed_at = now();
        $this->owed->save();

        $before = $this->floor();
        $this->issue('5')->assertSessionHasErrors('qty');

        $this->assertSame(0, bccomp($before, $this->floor(), 4), 'বাতিল বিলের উপহার গুদাম থেকে বেরিয়ে গেছে।');
    }

    /** ⭐ একই মানুষ: `gift` চাবি ছাড়া দরজা বন্ধ, চাবি পেলে খোলে। */
    public function test_the_same_person_needs_the_gift_key(): void
    {
        $keeper = User::factory()->create(['is_active' => true]);
        $keeper->companies()->attach($this->company->id, ['is_active' => true]);
        $keeper->forceFill(['current_company_id' => $this->company->id])->save();
        $keeper->givePermissionTo([
            Permission::findOrCreate('promotion.view', 'web'),
            Permission::findOrCreate('promotion.apply', 'web'),
        ]);

        $before = $this->floor();
        $this->issue('5', $keeper)->assertForbidden();
        $this->assertSame(0, bccomp($before, $this->floor(), 4));

        $keeper->givePermissionTo(Permission::findOrCreate('promotion.gift', 'web'));

        $this->issue('5', $keeper->fresh())->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp(bcsub($before, $this->floor(), 4), '5', 4),
            'চাবি পাওয়ার পরেও দরজা খোলেনি — তাহলে ৪০৩-টা চাবির জন্য ছিল না।');
    }
}
