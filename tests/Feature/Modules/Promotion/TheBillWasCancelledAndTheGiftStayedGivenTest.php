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
use App\Modules\Promotion\Models\PromotionBudget;
use App\Modules\Promotion\Services\BudgetGuard;
use App\Modules\Promotion\Services\GiftIssuer;
use App\Modules\Promotion\Services\PromotionExpiry;
use App\Modules\Promotion\Services\PromotionReversal;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * বিল বাতিল হলো, অথচ উপহারটা দেওয়াই থেকে গেল — স্পেক §১৮।
 *
 * ── ⚠️ মজুদ গোনা হয় মজুদের দরজা থেকে ────────────────────────────────
 * ⓘ দাবিগুলো [[StockService::floorQty()]] পড়ে, উপহারের কাগজ নয়। ⛔
 * কাগজ পড়লে দাবিটা মাপত *"কাগজে ফেরত লেখা হয়েছে"*, আর মাল গুদামে না
 * ফিরলেও সবুজ হত।
 */
final class TheBillWasCancelledAndTheGiftStayedGivenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Promotion $offer;

    private Product $gift;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->warehouse = Warehouse::query()->create([
            'code' => 'REVWH', 'name_en' => 'Reversal store', 'name_bn' => 'ফেরতের গুদাম', 'is_active' => true,
        ]);

        $this->gift = Product::query()->orderBy('id')->firstOrFail();
        $this->gift->track_batch = false;
        $this->gift->save();

        $stock = app(StockService::class);
        $stock->move(product: $this->gift, warehouse: $this->warehouse,
            sourceType: 'purchase_bill', sourceId: 5101, unplaced: '100');
        $stock->place(product: $this->gift, warehouse: $this->warehouse,
            qty: '100', sourceType: 'purchase_bill', sourceId: 5101);

        $this->offer = new Promotion([
            'name_en' => 'Cancel me',
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $this->offer->code = 'PROM-C-0001';
        $this->offer->type = PromotionType::BUY_X_GET_Y;
        $this->offer->status = PromotionStatus::ACTIVE;
        $this->offer->combines = PromotionCombines::BEST;
        $this->offer->created_by = $this->owner->id;
        $this->offer->save();
    }

    private function aBillWithAGift(int $billId, string $qty = '5'): PromotionApplication
    {
        $applied = PromotionApplication::query()->create([
            'promotion_id' => $this->offer->id,
            'source_type' => 'sales_invoice',
            'source_id' => $billId,
            'benefit_kind' => BenefitKind::GOODS,
            'benefit_amount' => $qty,
            'worth' => '500',
        ]);

        app(GiftIssuer::class)->issue($applied, $this->gift, $this->warehouse, $qty);

        return $applied;
    }

    private function floor(): string
    {
        return app(StockService::class)->floorQty($this->gift, $this->warehouse);
    }

    /** ⭐ বাতিল হলে উপহারের মাল গুদামে ফেরে। */
    public function test_cancelling_the_bill_brings_the_gift_back_to_the_floor(): void
    {
        $before = $this->floor();
        $this->aBillWithAGift(7101);
        $this->assertSame(0, bccomp(bcsub($before, $this->floor(), 4), '5', 4), 'দৃশ্যটাই বানানো যায়নি।');

        app(PromotionReversal::class)->forSource('sales_invoice', 7101);

        $this->assertSame(0, bccomp($before, $this->floor(), 4),
            'বিল বাতিল হলো, অথচ উপহারের মাল গুদামে ফেরেনি।');
    }

    /**
     * ⛔ দুইবার বাতিল চাপলেও মাল দুইবার ফেরে না।
     *
     * ⚠️ বিপজ্জনক ইনপুট: একই বিল দুইবার বাতিল। ⓘ দুইবার ফিরলে গুদামে ভুয়া
     * মজুদ দেখাত, আর সেটা বিক্রিও হয়ে যেত।
     */
    public function test_cancelling_twice_does_not_return_the_gift_twice(): void
    {
        $before = $this->floor();
        $this->aBillWithAGift(7201);

        $reversal = app(PromotionReversal::class);
        $reversal->forSource('sales_invoice', 7201);
        $reversal->forSource('sales_invoice', 7201);

        $this->assertSame(0, bccomp($before, $this->floor(), 4),
            'দ্বিতীয় বাতিলে মাল আবার ফিরেছে — গুদামে ভুয়া মজুদ।');
    }

    /**
     * ⭐ বাতিল বিল বাজেট খায় না।
     *
     * ⓘ না হলে বাতিল বিলটা বাজেট ধরে রাখত, আর নতুন ক্রেতা অফারটা পেতেন না।
     */
    public function test_a_cancelled_bill_gives_its_budget_back(): void
    {
        PromotionBudget::query()->create([
            'promotion_id' => $this->offer->id,
            'kind' => PromotionBudget::TOTAL,
            'ceiling' => '600',
        ]);

        $this->aBillWithAGift(7301);
        app(PromotionReversal::class)->forSource('sales_invoice', 7301);

        /* ⓘ বাতিলের পরে ৫০০ আবার খরচ করা যায় — না ফিরলে ১০০০ > ৬০০ হয়ে থামত */
        app(BudgetGuard::class)->assertRoomFor($this->offer, BenefitKind::GOODS, '500');

        $this->addToAssertionCount(1);
    }

    /**
     * ⭐ "সন্ধ্যা ৬টায় শেষ" অফার সেদিন রাত ৮টায় "মেয়াদ শেষ" হয়।
     *
     * ⚠️ বিপজ্জনক ইনপুট: **একই দিন**, শেষ সময়ের পরে। ⓘ কেবল তারিখ দেখলে
     * মধ্যরাত পর্যন্ত তালিকা *"চলছে"* বলত, অথচ ইঞ্জিন ততক্ষণে থেমে গেছে।
     */
    public function test_an_offer_ending_at_six_expires_the_same_evening(): void
    {
        $this->offer->ends_on = Carbon::today();
        $this->offer->ends_at = '18:00:00';
        $this->offer->save();

        $count = app(PromotionExpiry::class)->expireLapsed(Carbon::today()->setTime(20, 0));

        $this->assertSame(1, $count);
        $this->assertSame(PromotionStatus::EXPIRED, $this->offer->fresh()->status);
    }

    /** ⭐ পাল্টা-দাবি: সেদিন দুপুরে অফারটা এখনো চলছে — নাহলে "সব বন্ধ করো" লিখেও উপরেরটা সবুজ হত। */
    public function test_the_same_offer_is_still_running_at_noon(): void
    {
        $this->offer->ends_on = Carbon::today();
        $this->offer->ends_at = '18:00:00';
        $this->offer->save();

        $this->assertSame(0, app(PromotionExpiry::class)->expireLapsed(Carbon::today()->setTime(12, 0)));
        $this->assertSame(PromotionStatus::ACTIVE, $this->offer->fresh()->status);
    }
}
