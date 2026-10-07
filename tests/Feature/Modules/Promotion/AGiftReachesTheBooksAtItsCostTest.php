<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Services\GiftIssuer;
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
 * অফারের উপহার খাতায় — মালিকের পরিকল্পনা সংস্করণ ২, ৪ অক্টোবর ২০২৬ (IFRS 15: ফ্রি মাল আয়ে নয়, প্রচারের খরচে; [[GiftIssuer]])।
 *
 * ⛔ আগে উপহার কেবল তাক থেকে কমত — খাতার মজুদ বেশি দেখাত, খরচ উঠত না।
 *   · স্তরে ১০০ @ ৪০ → ৫টা উপহার: Dr প্রচারের খরচ ২০০ / Cr মজুদ ২০০, স্তরে ৯৫, উপহারের এককের দাম ৪০।
 *   · বিল বাতিলে উপহার ফেরে: Dr মজুদ ২০০ / Cr প্রচারের খরচ ২০০, স্তরে আবার ১০০ — খাতায় নিট শূন্য।
 *   · স্তর ছাড়া তাকের মাল (পুরনো খোলা মজুদ): কেনা দামে খরচ, উপহার থামে না; ফেরতে সেই দামেই উল্টো।
 */
final class AGiftReachesTheBooksAtItsCostTest extends TestCase
{
    use RefreshDatabase;

    private Promotion $offer;

    private Product $gift;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $this->warehouse = Warehouse::query()->create(['code' => 'GIFTWH', 'name_en' => 'Gift store', 'name_bn' => 'উপহারের গুদাম', 'is_active' => true]);
        // ⓘ নতুন পণ্য — ডেমোর পণ্যে বীজের আগের খরচের স্তর আছে, FIFO সেগুলো আগে টানত
        $this->gift = Product::query()->orderBy('id')->firstOrFail()->replicate(['public_id']);
        $this->gift->forceFill(['code' => 'GIFT-T1', 'barcode' => null, 'name_en' => 'Gift item', 'name_bn' => 'উপহার পণ্য', 'track_batch' => false, 'track_serial' => false, 'purchase_price' => '30'])->save();

        $stock = app(StockService::class);
        $stock->move(product: $this->gift, warehouse: $this->warehouse, sourceType: 'purchase_bill', sourceId: 6101, unplaced: '100');
        $stock->place(product: $this->gift, warehouse: $this->warehouse, qty: '100', sourceType: 'purchase_bill', sourceId: 6101);

        $this->offer = new Promotion(['name_en' => 'Gift books', 'starts_on' => Carbon::today()->subDay(), 'ends_on' => Carbon::today()->addWeek()]);
        $this->offer->code = 'PROM-G-0001';
        $this->offer->type = PromotionType::BUY_X_GET_Y;
        $this->offer->status = PromotionStatus::ACTIVE;
        $this->offer->combines = PromotionCombines::BEST;
        $this->offer->created_by = $owner->id;
        $this->offer->save();
    }

    public function test_a_gift_from_cost_layers_books_fifo_cost_and_its_return_takes_it_back(): void
    {
        app(CostLayerService::class)->receive($this->gift, '100', '40', 'purchase_bill', 6101);

        $issue = $this->give(8101, '5');

        $this->assertSame('200.0000', $this->net(StandardChart::PROMOTION_EXPENSE), '⛔ প্রচারের খরচ খাতায় ওঠেনি।');
        $this->assertSame('-200.0000', $this->net(StandardChart::INVENTORY), '⛔ মজুদের খাত কমেনি।');
        $this->assertSame(0, bccomp((string) $issue->fresh()->unit_cost, '40', 4), '⛔ উপহারের দাম স্তরের নয়।');
        $this->assertSame(0, bccomp(app(CostLayerService::class)->qtyOnHand($this->gift), '95', 4), '⛔ স্তর থেকে টানা হয়নি।');

        app(PromotionReversal::class)->forSource('sales_invoice', 8101);

        $this->assertSame('0.0000', $this->net(StandardChart::PROMOTION_EXPENSE), '⛔ ফেরতে খরচ উল্টায়নি।');
        $this->assertSame('0.0000', $this->net(StandardChart::INVENTORY));
        $this->assertSame(0, bccomp(app(CostLayerService::class)->qtyOnHand($this->gift), '100', 4), '⛔ স্তরে মাল ফেরেনি।');
    }

    public function test_shelf_stock_without_layers_still_gives_at_purchase_price_and_returns_alike(): void
    {
        $this->give(8201, '5');

        $this->assertSame('150.0000', $this->net(StandardChart::PROMOTION_EXPENSE), '⛔ স্তর ছাড়া মালে কেনা দামে খরচ ওঠেনি।');

        app(PromotionReversal::class)->forSource('sales_invoice', 8201);

        $this->assertSame('0.0000', $this->net(StandardChart::PROMOTION_EXPENSE), '⛔ ফেরতে কেনা দামের খরচ উল্টায়নি।');
    }

    /** ⓘ ৪ অক্টোবরের আগের উপহার — খাতায় খরচ ওঠেনি; ফেরতে উল্টো দাখিলাও নয় (নইলে খরচ ঋণাত্মক হত) */
    public function test_an_old_gift_that_never_reached_the_books_returns_without_an_entry(): void
    {
        // ⓘ স্তর থেকে টানা উপহার, তারপর খাতার দাখিলা মুছে "আগের দিনের" উপহার বানানো — স্তরে ফেরে, খাতায় কিছু নয়
        app(CostLayerService::class)->receive($this->gift, '100', '40', 'purchase_bill', 6101);
        $issue = $this->give(8301, '5');
        LedgerEntry::query()->where('source_type', GiftIssuer::LEDGER_SOURCE)->where('source_id', $issue->id)->delete();

        app(PromotionReversal::class)->forSource('sales_invoice', 8301);

        $this->assertFalse(LedgerEntry::query()->where('source_type', GiftIssuer::LEDGER_SOURCE.'_return')->exists(), '⛔ খাতায় না-ওঠা উপহারের ফেরতে দাখিলা বসেছে।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function give(int $billId, string $qty): \App\Modules\Promotion\Models\PromotionGiftIssue
    {
        $applied = PromotionApplication::query()->create([
            'promotion_id' => $this->offer->id, 'source_type' => 'sales_invoice', 'source_id' => $billId,
            'benefit_kind' => BenefitKind::GOODS, 'benefit_amount' => $qty, 'worth' => '500',
        ]);

        return app(GiftIssuer::class)->issue($applied, $this->gift, $this->warehouse, $qty);
    }

    /** একটা খাতের নিট (Dr − Cr) — উপহার আর তার ফেরতের দাখিলা মিলিয়ে */
    private function net(string $code): string
    {
        return (string) LedgerEntry::query()
            ->whereIn('source_type', [GiftIssuer::LEDGER_SOURCE, GiftIssuer::LEDGER_SOURCE.'_return'])
            ->where('account_id', StandardChart::find($code)->id)
            ->get()->reduce(fn ($c, $e) => bcadd($c, bcsub((string) $e->debit, (string) $e->credit, 4), 4), '0.0000');
    }
}
