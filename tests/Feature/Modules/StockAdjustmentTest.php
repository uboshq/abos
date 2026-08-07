<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * গণনার পর সমন্বয় — তাক, দাম আর খাতা, তিনটাই একসাথে।
 *
 * ── কেন এই ফাইলটা আছে ───────────────────────────────────────────────
 * সমন্বয় এতদিন কেবল গুদামের হিসাব বদলাত, খতিয়ানে কিছুই বসাত না। গণনায়
 * পাঁচ বস্তা কম পাওয়া গেলে তাক থেকে কমত, অথচ ব্যালেন্স শিটে মজুদের টাকা
 * যেমন ছিল তেমনই থাকত — ঘাটতিটা কোনো খরচ হিসেবে কোথাও বসত না, তাই
 * মুনাফা ঠিক ততটাই বেশি দেখাত।
 *
 * টেস্টগুলো তাই দুইটা সংখ্যা পাশাপাশি রাখে: তাকে কত আছে, আর খাতা কত
 * বলছে। একটা নথির নিজের শুদ্ধতা এখানে যথেষ্ট নয় — ভুলটা দুইটার মাঝখানে।
 *
 * বিস্তারিত: docs/Finding — Inventory is valued two different ways.md
 */
class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    /**
     * মাল কম পাওয়া গেলে ক্ষতিটা খরচ হয়ে বসে, আর মজুদ কমে।
     *
     * এটাই আসল ভুলটার সরাসরি পাহারা। আগে তাক থেকে কমত আর খাতায় কিছুই
     * হত না, তাই হারিয়ে যাওয়া মাল ব্যালেন্স শিটে সম্পদ হয়েই থাকত।
     */
    public function test_goods_missing_at_a_count_become_a_loss_in_the_books(): void
    {
        $onFloor = $this->floor();
        $inventoryBefore = $this->balanceOf(StandardChart::INVENTORY);
        $lossBefore = $this->balanceOf(StandardChart::INVENTORY_SHORTAGE_SURPLUS);

        // তিন বস্তা কম পাওয়া গেল
        $this->adjustments()->adjust(
            product: $this->product,
            warehouse: $this->warehouse,
            countedQty: bcsub($onFloor, '3', 4),
            reason: $this->reason(ReasonCode::STOCK_ADJUSTMENT),
        );

        $cost = bcmul('3', (string) $this->product->purchase_price, 4);

        // মজুদ কমল ঠিক ওই তিন বস্তার দামে
        $this->assertSame(
            bcsub($inventoryBefore, $cost, 4),
            $this->balanceOf(StandardChart::INVENTORY),
        );

        // আর সমান অঙ্কটা ক্ষতির খাতে বসল
        $this->assertSame(
            bcadd($lossBefore, $cost, 4),
            $this->balanceOf(StandardChart::INVENTORY_SHORTAGE_SURPLUS),
        );
    }

    /**
     * মাল বেশি পাওয়া গেলে মজুদ বাড়ে, আর সেটা দর ছাড়া হয় না।
     *
     * ── কেন দরটা মানুষকে বলতে হয় ───────────────────────────────────
     * গণনায় বেশি পাওয়া মালের কোনো চালান নেই, তাই তার দামও কোথাও লেখা
     * নেই। একটা দর ধরে নিলে (যেমন পণ্যের ক্রয়মূল্য) ঠিক সেই ভুলটাই ফিরে
     * আসত যেটা সারাতে স্তরগুলো বসানো হয়েছে।
     */
    public function test_surplus_needs_a_rate_and_then_raises_the_inventory(): void
    {
        $onFloor = $this->floor();
        $inventoryBefore = $this->balanceOf(StandardChart::INVENTORY);
        $layerValueBefore = app(CostLayerService::class)->valueOnHand($this->product);

        $this->adjustments()->adjust(
            product: $this->product,
            warehouse: $this->warehouse,
            countedQty: bcadd($onFloor, '5', 4),
            reason: $this->reason(ReasonCode::STOCK_ADJUSTMENT),
            unitCost: '90',
        );

        $this->assertSame(
            bcadd($inventoryBefore, '450.0000', 4),
            $this->balanceOf(StandardChart::INVENTORY),
        );

        /*
         * আর স্তরেও ঠিক ওই ৪৫০ টাকাই যোগ হলো।
         *
         * ── এখানে "৫টা বের করে দেখা" যেত না, আর কেন ─────────────────
         * প্রথমে তাই লেখা হয়েছিল, আর টেস্টটা ১৭,০০০ পেয়ে লাল হয়েছিল।
         * কোড ঠিকই ছিল: FIFO পুরনো স্তর থেকে আগে বের করে, আর খোলা
         * মজুদের দর ৩,৪০০। উদ্বৃত্তের বস্তাগুলো তাকের পেছনে গিয়ে
         * দাঁড়ায়, সামনে নয় — সেটাই FIFO-র মানে।
         */
        $this->assertSame(
            bcadd($layerValueBefore, '450.0000', 4),
            app(CostLayerService::class)->valueOnHand($this->product),
        );
    }

    /** দর ছাড়া উদ্বৃত্ত বসানো যায় না। */
    public function test_surplus_without_a_rate_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->adjustments()->adjust(
            product: $this->product,
            warehouse: $this->warehouse,
            countedQty: bcadd($this->floor(), '5', 4),
            reason: $this->reason(ReasonCode::STOCK_ADJUSTMENT),
        );
    }

    /**
     * মিলে গেলে কিছুই হয় না — সারিও নয়, দাখিলাও নয়।
     *
     * শূন্য সারি খতিয়ানে শুধু ভিড় বাড়ায়, আর শূন্য টাকার দাখিলা পড়ে
     * মানুষ ভাবত কিছু একটা ঘটেছে।
     */
    public function test_a_count_that_matches_writes_nothing(): void
    {
        $entriesBefore = LedgerEntry::query()->count();

        $movement = $this->adjustments()->adjust(
            product: $this->product,
            warehouse: $this->warehouse,
            countedQty: $this->floor(),
            reason: $this->reason(ReasonCode::STOCK_ADJUSTMENT),
        );

        $this->assertNull($movement);
        $this->assertSame($entriesBefore, LedgerEntry::query()->count());
    }

    /**
     * ঘাটতির অঙ্কটা মালের নিজের দাম থেকে, পণ্যের তালিকা থেকে নয়।
     *
     * দুইটা আলাদা দরে মাল ঢুকলে FIFO পুরনোটাই আগে বের করে, আর ক্ষতির
     * অঙ্কটাও সেই দরেই বসে। পণ্যের মাস্টারে লেখা দর ধরলে সংখ্যাটা অন্য
     * হত — আর ঠিক সেটাই ছিল মূল রোগ।
     */
    public function test_the_loss_is_priced_from_the_goods_own_consignment(): void
    {
        $costs = app(CostLayerService::class);

        // তাকের পুরনো মাল সরিয়ে দুইটা পরিষ্কার স্তর বসানো
        $costs->issue($this->product, $costs->qtyOnHand($this->product), 'test_clear', 1);
        $costs->receive($this->product, '10', '100', 'test_in', 1, 'IN-1', '2026-08-01');
        $costs->receive($this->product, '10', '150', 'test_in', 2, 'IN-2', '2026-08-05');

        $lossBefore = $this->balanceOf(StandardChart::INVENTORY_SHORTAGE_SURPLUS);

        $this->adjustments()->adjust(
            product: $this->product,
            warehouse: $this->warehouse,
            countedQty: bcsub($this->floor(), '4', 4),
            reason: $this->reason(ReasonCode::STOCK_ADJUSTMENT),
        );

        // চারটাই পুরনো স্তর থেকে — ৪ × ১০০, ১৫০ নয়
        $this->assertSame(
            bcadd($lossBefore, '400.0000', 4),
            $this->balanceOf(StandardChart::INVENTORY_SHORTAGE_SURPLUS),
        );
    }

    private function adjustments(): StockAdjustmentService
    {
        return app(StockAdjustmentService::class);
    }

    private function floor(): string
    {
        return app(StockService::class)->floorQty($this->product, $this->warehouse);
    }

    private function reason(string $context): ReasonCode
    {
        return ReasonCode::query()->inContext($context)->active()->orderBy('id')->firstOrFail();
    }

    private function balanceOf(string $code): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        return LedgerEntry::query()
            ->where('account_id', $account->id)
            ->get()
            ->reduce(
                fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
                '0.0000',
            );
    }
}
