<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Models\PricingRule;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\MarginGuard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মার্জিন মাপা হয় বাছা লটের খরচে — মালিকের প্রশ্ন, ৪ অক্টোবর ২০২৬: "মার্জিন এত বেশি দেখায় কেন?"
 *
 * ⛔ কাউন্টারে ঘি টোস্ট (লট OM-764) 7.85%, মিল্ক মেরী (লট OM-619) 14.55% দেখাত, অথচ দর বসানো 4%-এ। কারণ: পর্দা আর
 * দেয়াল দুইটাই পণ্যের সবচেয়ে পুরনো স্তরের দাম নিত, আর বিল খরচ নেয় বাছা লটের স্তর থেকে ([[CostLayerService::issue()]])।
 * পুরনো স্তর সস্তা হলে মার্জিন ফুলত — আর সীমার নিচের বিক্রিও পার হয়ে যেত।
 */
final class TheMarginReadsTheChosenLotsCostTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Batch $old;

    private Batch $new;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
        app(SettingsService::class)->set(PricingRule::POLICY, PricingRule::ALLOW);

        $this->product = app(ProductService::class)->create([
            'name_en' => 'Lot Margin Toast',
            'name_bn' => 'লট মার্জিন টোস্ট',
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'purchase_price' => '1',
            'sale_price' => '1',
            'reorder_level' => '0',
        ]);

        // ⓘ পুরনো লট সস্তা (১০০), নতুন লট ১৫০ — দর নতুন লটের খরচে ৪%: ১৫০ ÷ ০.৯৬ = ১৫৬.২৫
        $this->old = $this->lot('OLD-1', '100', now()->subMonth()->toDateString());
        $this->new = $this->lot('NEW-1', '150', now()->toDateString());
    }

    public function test_the_counter_screen_gives_each_lot_its_own_cost(): void
    {
        $costs = app(MarginGuard::class)->screenCosts([$this->product])[$this->product->id];

        $this->assertSame(0, bccomp((string) $costs['lots'][$this->new->id], '150', 4), '⛔ নতুন লটের সারিতে পুরনো স্তরের দাম — মার্জিন ফুলে দেখায়।');
        $this->assertSame(0, bccomp((string) $costs['lots'][$this->old->id], '100', 4));
        $this->assertSame(0, bccomp((string) $costs['cost'], '100', 4), 'লট ছাড়া সারি আগের মতো FIFO-র মাথা।');
    }

    public function test_the_wall_judges_the_new_lot_at_its_own_cost(): void
    {
        $verdict = $this->judge($this->new, '156.25');

        $this->assertSame('4.00', $verdict->lines[0]->marginPercent, '⛔ দেয়াল পুরনো সস্তা স্তরে মাপল — 36% দেখাত, আসলে 4%।');
    }

    public function test_a_sale_under_the_floor_on_the_new_lot_is_caught(): void
    {
        app(SettingsService::class)->set(MarginGuard::FLOOR, '5');

        $this->assertTrue($this->judge($this->new, '156.25')->isBelow(),
            '⛔ ৪% মার্জিনের বিক্রি ৫% সীমা পার হলো — পুরনো সস্তা স্তর দেখে দেয়াল খোলা ছিল।');
        $this->assertFalse($this->judge($this->old, '156.25')->isBelow(), 'পুরনো লট নিজের খরচে (১০০) সীমার উপরে।');
    }

    /*
     * ⭐ ফ্রির খরচও মার্জিনে — মালিকের সিদ্ধান্ত "ক", ৪ অক্টোবর ২০২৬। ⓘ লটের ফ্রি ভাণ্ডার থেকে এলে খরচ শূন্য (বিনা দামে
     * এসেছিল); নিজের মাল থেকে গেলে (`sales.free_beyond_pool`) লটের স্তরের খরচে।
     */
    public function test_one_free_unit_from_own_stock_costs_the_lots_cost(): void
    {
        app(SettingsService::class)->set('sales.free_beyond_pool', true);

        $this->assertSame('4.00', $this->judge($this->new, '156.25', free: '0')->lines[0]->marginPercent, 'প্রস্তুতিটাই ভুল — ফ্রি ছাড়া 4%।');
        $this->assertSame('-92.00', $this->judge($this->new, '156.25', free: '1')->lines[0]->marginPercent,
            '⛔ নিজের মাল থেকে দেওয়া ১টা ফ্রির খরচ (১৫০) মার্জিনে ধরা হয়নি।');
    }

    public function test_free_from_the_lots_free_pool_costs_nothing(): void
    {
        app(SettingsService::class)->set('sales.free_beyond_pool', true);

        app(StockService::class)->move(
            product: $this->product,
            warehouse: Warehouse::query()->where('is_default', true)->firstOrFail(),
            sourceType: StockService::ADJUSTMENT,
            sourceId: $this->new->id,
            free: '1',
            batch: $this->new,
        );

        $this->assertSame('4.00', $this->judge($this->new, '156.25', free: '1')->lines[0]->marginPercent,
            '⛔ সরবরাহকারীর ফ্রি ভাণ্ডারের মাল (বিনা দামে আসা) খরচ হিসেবে ধরা হয়েছে।');
    }

    public function test_with_the_switch_off_free_always_comes_from_the_pool(): void
    {
        app(SettingsService::class)->set('sales.free_beyond_pool', false);

        $this->assertSame('4.00', $this->judge($this->new, '156.25', free: '1')->lines[0]->marginPercent,
            'সুইচ বন্ধে ফ্রি কেবল ভাণ্ডার থেকে — খরচ শূন্য।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function judge(Batch $lot, string $rate, string $free = '0'): \App\Modules\Sales\Services\MarginVerdict
    {
        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'batch_id' => $lot->id,
            'delivered_qty' => '1',
            'rate' => $rate,
        ]]);

        // ⓘ সাধারণ চালানের create() ফ্রি রাখে না — ফ্রি বসায় কাউন্টার ([[DirectSaleService]]); এখানে সেভাবেই সারিতে
        $challan->lines()->update(['free_qty' => $free]);

        return app(MarginGuard::class)->judge($challan->fresh(['lines']));
    }

    private function lot(string $no, string $cost, string $date): Batch
    {
        $batch = Batch::query()->create([
            'company_id' => CompanyContext::id(),
            'product_id' => $this->product->id,
            'batch_no' => $no,
        ]);

        app(CostLayerService::class)->receive(
            product: $this->product,
            qty: '10',
            unitCost: $cost,
            sourceType: 'opening',
            sourceId: $batch->id,
            documentNo: 'OPEN-'.$no,
            date: $date,
            batch: $batch,
        );

        return $batch;
    }
}
