<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * সরাসরি বিক্রয় — DMS-এর Direct Delivery Challan-এর সমতুল্য।
 *
 * ── এখানে যা প্রমাণ করা দরকার ─────────────────────────────────────────
 * এটা একটা দ্রুত পথ, আলাদা হিসাব নয়। এক চাপে চালান, বিল আর জমা — কিন্তু
 * দাখিলাগুলো ঠিক সেইগুলোই যেগুলো লম্বা পথে বসত। আলাদা হলে অমিলটা ধরা পড়ত
 * মাস শেষে।
 *
 * আর দ্বিতীয় দাবি: ফ্রি ও উপহার ফ্রি ভাণ্ডার থেকে যায়, বিক্রির মজুদ থেকে
 * নয় — নাহলে ফ্রি মালের ক্রয়মূল্য বিক্রির খরচে মিশে মুনাফা বেশি দেখাত।
 */
class DirectSaleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private Product $gift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $products = Product::query()->orderBy('id')->take(2)->get();
        $this->product = $products->first();
        $this->gift = $products->last();
    }

    private function sales(): DirectSaleService
    {
        return app(DirectSaleService::class);
    }

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    /** ফ্রি ভাণ্ডারে কিছু মাল রাখা — প্রস্তুতকারকের পাঠানো। */
    private function receiveFree(Product $product, string $qty): void
    {
        $this->stock()->move(
            product: $product,
            warehouse: $this->warehouse,
            sourceType: 'test_free_receipt',
            sourceId: 1,
            free: $qty,
        );
    }

    private function balanceOf(string $code): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        return LedgerEntry::query()->where('account_id', $account->id)->get()->reduce(
            fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
            '0',
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  list<array<string, mixed>>  $gifts
     * @return array{challan: DeliveryChallan, invoice: SalesInvoice, change: string}
     */
    private function sell(array $extra = [], array $gifts = [], string $qty = '10', string $rate = '100'): array
    {
        return $this->sales()->complete(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                ...$extra,
            ],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate,
                'free_qty' => $extra['free_qty'] ?? '0']],
            $gifts,
        );
    }

    // ── এক চাপে তিনটা কাগজ ──────────────────────────────────────────────

    /**
     * চালান, বিল আর জমা — একসাথে, আর দাখিলাগুলো লম্বা পথের মতোই।
     */
    public function test_one_press_makes_a_challan_an_invoice_and_a_collection(): void
    {
        $floorBefore = $this->stock()->floorQty($this->product, $this->warehouse);

        $result = $this->sell(['deposit' => '1000']);

        $this->assertSame(DocumentStatus::CONFIRMED, $result['challan']->status);
        $this->assertSame(DocumentStatus::CONFIRMED, $result['invoice']->status);

        // মাল বেরিয়েছে
        $this->assertSame(0, bccomp(
            $this->stock()->floorQty($this->product, $this->warehouse),
            bcsub($floorBefore, '10', 4),
            4,
        ));

        // আয়, আর টাকা এসে যাওয়ায় পাওনা শূন্য
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::SALES), '-1000', 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::RECEIVABLE), '0', 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CASH_IN_HAND), '1000', 4));

        // বিক্রীত পণ্যের ব্যয়ও বসেছে — লম্বা পথের মতোই
        $cost = bcmul('10', (string) $this->product->purchase_price, 4);
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::COST_OF_GOODS_SOLD), $cost, 4));
    }

    /**
     * বিলের লাইন চালানের লাইনের সাথে বাঁধা।
     *
     * না বাঁধলে "মাল গেছে, বিল হয়নি" রিপোর্টটা এই বিক্রিগুলোকে চিরকাল
     * বাকি দেখাত — অথচ বিল ওই মুহূর্তেই কাটা হয়েছে।
     */
    public function test_the_invoice_lines_point_at_the_challan_lines(): void
    {
        $result = $this->sell();

        $line = $result['invoice']->lines->first();

        $this->assertNotNull($line->delivery_challan_line_id);
        $this->assertSame(
            $result['challan']->lines->first()->id,
            $line->delivery_challan_line_id,
        );
    }

    /** মাল গেছে-বিল হয়নি রিপোর্টে সরাসরি বিক্রি ঝুলে থাকে না। */
    public function test_a_direct_sale_never_shows_as_uninvoiced(): void
    {
        $this->sell();

        $result = app(ReportEngine::class)->run('sales.uninvoiced', [
            'from' => now()->subYear()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);

        $this->assertCount(0, $result->rows);
    }

    // ── ফ্রি ও উপহার ────────────────────────────────────────────────────

    /**
     * ফ্রি পরিমাণ ফ্রি ভাণ্ডার থেকে কাটে, বিক্রির মজুদ থেকে নয়।
     */
    public function test_free_quantity_comes_out_of_the_free_pool(): void
    {
        $this->receiveFree($this->product, '50');

        $floorBefore = $this->stock()->floorQty($this->product, $this->warehouse);
        $freeBefore = $this->stock()->freeQty($this->product, $this->warehouse);

        $this->sell(['free_qty' => '3']);

        $this->assertSame(0, bccomp(
            $this->stock()->floorQty($this->product, $this->warehouse),
            bcsub($floorBefore, '10', 4),
            4,
        ), 'বিক্রির মজুদ থেকে কেবল বিক্রির পরিমাণ');

        $this->assertSame(0, bccomp(
            $this->stock()->freeQty($this->product, $this->warehouse),
            bcsub($freeBefore, '3', 4),
            4,
        ), 'ফ্রি পরিমাণ ফ্রি ভাণ্ডার থেকে');
    }

    /**
     * ফ্রি পরিমাণ বিলে টাকা হয়ে বসে না।
     *
     * বসলে গ্রাহক ভাবতেন ফ্রি মালের জন্যও টাকা নেওয়া হয়েছে — আর সেটাই
     * কাউন্টারে সবচেয়ে বেশি ঝগড়ার কারণ।
     */
    public function test_free_quantity_is_not_charged_for(): void
    {
        $this->receiveFree($this->product, '50');

        $result = $this->sell(['free_qty' => '3']);

        // ১০ × ১০০ = ১,০০০ — ফ্রি তিনটার কোনো দাম নেই
        $this->assertSame(0, bccomp((string) $result['invoice']->total, '1000', 4));
        $this->assertCount(1, $result['invoice']->lines);
    }

    /**
     * উপহার অন্য পণ্য, আর সেটাও ফ্রি ভাণ্ডার থেকে।
     */
    public function test_a_gift_is_a_different_product_from_the_free_pool(): void
    {
        $this->receiveFree($this->gift, '40');

        $freeBefore = $this->stock()->freeQty($this->gift, $this->warehouse);

        $result = $this->sell([], [[
            'product_id' => $this->gift->id,
            'against_product_id' => $this->product->id,
            'qty' => '2',
            'remarks' => 'বিক্রয়ের জন্য নয়',
        ]]);

        $this->assertCount(1, $result['challan']->giftLines);

        $this->assertSame(0, bccomp(
            $this->stock()->freeQty($this->gift, $this->warehouse),
            bcsub($freeBefore, '2', 4),
            4,
        ));

        // উপহার বিলে যোগ হয় না
        $this->assertSame(0, bccomp((string) $result['invoice']->total, '1000', 4));
    }

    /** উপহার কোন পণ্যের সাথে গেল তা লেখা থাকে। */
    public function test_a_gift_records_what_it_was_given_against(): void
    {
        $this->receiveFree($this->gift, '40');

        $result = $this->sell([], [[
            'product_id' => $this->gift->id,
            'against_product_id' => $this->product->id,
            'qty' => '1',
        ]]);

        $this->assertSame(
            $this->product->id,
            (int) $result['challan']->giftLines->first()->against_product_id,
        );
    }

    /**
     * যে ফ্রি মাল নেই তা দেওয়া যায় না — বিক্রির মজুদ ভরা থাকলেও।
     */
    public function test_a_gift_without_free_stock_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->sell([], [[
            'product_id' => $this->gift->id,
            'against_product_id' => $this->product->id,
            'qty' => '5',
        ]]);
    }

    // ── কাগজের নিচের ঘরগুলো ─────────────────────────────────────────────

    /** DO নম্বর, খরচ, রাউন্ডিং আর জমা চালানে লেখা থাকে। */
    public function test_the_document_carries_the_counter_figures(): void
    {
        $result = $this->sell([
            'do_no' => 'DO-8821',
            'expense_amount' => '50',
            'rounding_amount' => '2',
            'deposit' => '600',
            'credit_period_days' => 7,
        ]);

        $challan = $result['challan']->fresh();

        $this->assertSame('DO-8821', $challan->do_no);
        $this->assertSame(0, bccomp((string) $challan->expense_amount, '50', 4));
        $this->assertSame(0, bccomp((string) $challan->rounding_amount, '2', 4));
        $this->assertSame(0, bccomp((string) $challan->deposit_amount, '600', 4));
        $this->assertSame(7, (int) $challan->credit_period_days);
    }

    /**
     * আংশিক জমা দিলে বাকিটা গ্রাহকের নামে পাওনা থাকে।
     */
    public function test_a_part_deposit_leaves_the_rest_outstanding(): void
    {
        $result = $this->sell(['deposit' => '400']);

        $this->assertSame(0, bccomp($result['invoice']->fresh()->dueAmount(), '600', 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::RECEIVABLE), '600', 4));
    }

    /** বেশি জমা দিলে ফেরত হিসাব হয়, কিন্তু খাতায় বসে না। */
    public function test_change_is_worked_out_but_never_posted(): void
    {
        $result = $this->sell(['deposit' => '1500']);

        $this->assertSame(0, bccomp($result['change'], '500', 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CASH_IN_HAND), '1000', 4));
    }

    /**
     * বাকির মেয়াদ না বললে গ্রাহকের নিজের মেয়াদ।
     *
     * শূন্য আর "বলা হয়নি" এক নয় — শূন্য মানে আজই দিতে হবে।
     */
    public function test_the_due_date_falls_back_to_the_customer_terms(): void
    {
        $result = $this->sell();

        $this->assertSame(
            now()->addDays((int) $this->customer->credit_days)->toDateString(),
            $result['invoice']->fresh()->due_on?->toDateString(),
        );
    }

    // ── পর্দা ও অনুমতি ──────────────────────────────────────────────────

    public function test_the_screen_carries_every_stock_figure(): void
    {
        $response = $this->actingAs($this->user)->get(route('sales.direct.create'))->assertOk();

        $product = $response->viewData('products')->first();

        // নমুনা দাবি করে Main / Free / Reserved / Available সরাসরি দেখা যাবে
        foreach (['main', 'reserved', 'available', 'free', 'free_available'] as $figure) {
            $this->assertObjectHasProperty($figure, $product, "মজুদের ঘর নেই: {$figure}");
        }
    }

    public function test_selling_through_the_screen_ends_at_the_receipt(): void
    {
        $response = $this->actingAs($this->user)->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'deposit' => '500',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => '4', 'rate' => '125'],
            ],
        ]);

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('sales.print.invoice', [
            'invoice' => $invoice->id,
            'paper' => '80mm',
        ]));

        $this->assertSame(0, bccomp((string) $invoice->total, '500', 4));
    }

    public function test_a_user_without_the_permission_cannot_reach_it(): void
    {
        $stranger = User::factory()->create();
        $stranger->companies()->attach($this->company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($stranger)->get(route('sales.direct.create'))->assertForbidden();
    }
}
