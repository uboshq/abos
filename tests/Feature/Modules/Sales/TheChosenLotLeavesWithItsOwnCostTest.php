<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * বাছা লটটাই গুদাম থেকে যায় — আর খাতায় যায় **ঐ লটেরই** দাম।
 *
 * ── ⭐ চেকলিস্ট "ব্যবসা চালু", §৩ — লট বাছা ─────────────────────────
 * *"বাছাই করা লটই গুদাম থেকে যায়"* — চলাচলের সারি আর খরচের স্তর দুইটাই।
 * ⓘ [[TheSellerChoseALotAndAnotherOneLeftTest]] চলাচলের সারি মাপে; এই
 * ফাইল মাপে টাকা: কোন স্তর থেকে খরচ টানা হলো, আর পাঁচ মিল।
 *
 * ── ⓘ হাতে গোনা অঙ্ক ─────────────────────────────────────────────────
 * একটা নতুন লট-ধরা পণ্য, কেবল দুই লটে মাল — ⚠️ ইচ্ছাকৃত দুই দরে:
 *   LOT-OLD  ১০০ × ৩,০০০  (মেয়াদ আগে — FEFO আর FIFO দুইটাই এটা নিত)
 *   LOT-NEW  ১০০ × ৩,৮০০
 * বিক্রি: LOT-NEW থেকে ১০ × ৪,০০০ = ৪০,০০০, নগদ জমা ১৫,০০০।
 *   খরচ   ১০ × ৩,৮০০ = ৩৮,০০০   (বাছা লটের দর)
 *   পাওনা ৪০,০০০ − ১৫,০০০ = ২৫,০০০
 *   লাভ   ৪০,০০০ − ৩৮,০০০ = ২,০০০
 * ⛔ দুই লট এক দরে হলে এই ফাইল কিছুই প্রমাণ করত না — কোন স্তর থেকে
 * টানা হলো, খরচ একই দেখাত।
 */
final class TheChosenLotLeavesWithItsOwnCostTest extends TestCase
{
    use ReadsTheCounterBooks;
    use RefreshDatabase;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private Batch $older;

    private Batch $newer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        app(SettingsService::class)->set('customer.zero_limit_blocks', false);

        $this->customer = app(CustomerService::class)->create([
            'name_en' => 'Lot Proof Traders',
            'name_bn' => 'লট প্রমাণ ট্রেডার্স',
            'credit_limit' => '0',
            'credit_days' => 7,
        ]);
        $this->customer->forceFill(['credit_limit' => '100000'])->save();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->product = app(ProductService::class)->create([
            'name_en' => 'Lot Proof Dal 50kg',
            'name_bn' => 'লট প্রমাণ ডাল ৫০ কেজি',
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'purchase_price' => '3000',
            'sale_price' => '4000',
        ]);
        $this->product->forceFill(['track_batch' => true])->save();

        $this->older = $this->lot('LOT-OLD', '2027-01-01', '3000');
        $this->newer = $this->lot('LOT-NEW', '2028-01-01', '3800');
    }

    /**
     * একটা লট — মাল, তার খরচের স্তর, আর খাতায় মজুদ।
     *
     * ⓘ স্তর আর চলাচল একই উৎসে বাঁধা (`opening` · লটের আইডি) — ক্রয়ের পথে
     * যেমন বিলের উৎসে বাঁধা থাকে। ⚠️ খাতাতেও বসে, নাহলে মজুদ খাত আর স্তরের
     * মূল্য শুরুতেই আলাদা হত।
     */
    private function lot(string $no, string $expiry, string $cost): Batch
    {
        $batch = Batch::query()->create([
            'company_id' => CompanyContext::id(),
            'product_id' => $this->product->id,
            'batch_no' => $no,
            'expiry_date' => $expiry,
        ]);

        $movement = app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'opening',
            sourceId: $batch->id,
            floor: '100',
            date: now()->toDateString(),
            documentNo: 'OPEN-'.$no,
            batch: $batch,
        );

        app(CostLayerService::class)->receive(
            product: $this->product,
            qty: '100',
            unitCost: $cost,
            sourceType: 'opening',
            sourceId: $batch->id,
            documentNo: 'OPEN-'.$no,
            // ⓘ খোলা মজুদের পথ ([[OpeningStockService::bringIn()]]) যেভাবে এখন স্তর বানায় — লটসহ
            batch: $batch,
        );

        app(OpeningBalanceService::class)->forInventory(
            sourceId: $movement->id,
            documentNo: 'OPEN-'.$no,
            amount: bcmul('100', $cost, 4),
        );

        return $batch;
    }

    private function key(Batch $batch): string
    {
        return $this->product->id.'/'.$this->warehouse->id.'/'.$batch->id;
    }

    /**
     * ⭐ বাছা লট বেরোয়, তার স্তর থেকে খরচ আসে, আর পাঁচ মিল হাতের গোনায়।
     */
    public function test_the_chosen_lot_leaves_and_its_own_cost_reaches_the_books(): void
    {
        $before = $this->books($this->customer, [(int) $this->product->id]);

        $this->assertMoney('680000', $before['layers'], 'দৃশ্যটাই বানানো যায়নি — দুই লটের স্তর ৩,০০,০০০ + ৩,৮০,০০০ নয়');

        $this->post(route('sales.direct.store'), [
            'own_transport' => '1', // ⓘ ধাপ ৫ — নিশ্চিতে পরিবহন লাগে ([[TransportRule]]); এই দাবি অন্য কিছু মাপে
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'deposit' => '15000',
            'lines' => [[
                'product_id' => $this->product->id,
                'qty' => '10',
                'rate' => '4000',
                'batch_id' => $this->newer->id,
            ]],
        ])->assertSessionHasNoErrors();

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame('confirmed', $invoice->status, 'দৃশ্যটাই বানানো যায়নি — বিক্রি পাকা হয়নি।');

        // ── চলাচলের সারি: কেবল LOT-NEW থেকে ১০
        $out = DB::table('inv_stock_movements')
            ->where('product_id', $this->product->id)
            ->where('floor_change', '<', 0)
            ->groupBy('batch_id')
            ->selectRaw('batch_id, SUM(floor_change) as qty')
            ->pluck('qty', 'batch_id')
            ->map(fn ($q) => bcadd((string) $q, '0', 4))
            ->all();

        $this->assertSame([$this->newer->id => '-10.0000'], $out,
            '⛔ বাছা লট LOT-NEW, অথচ তাক থেকে অন্য লট (বা আরও লট) বেরিয়েছে।');

        // ── খরচের স্তর: টানটা LOT-NEW-এর স্তর থেকে, ৩,৮০০ দরে
        $drawn = DB::table('inv_cost_layer_uses as u')
            ->join('inv_cost_layers as l', 'l.id', '=', 'u.cost_layer_id')
            ->where('u.source_type', SalesInvoice::STOCK_SOURCE)
            ->where('u.source_id', $invoice->id)
            ->groupBy('l.source_id')
            ->selectRaw('l.source_id as lot, SUM(u.qty) as qty, SUM(u.amount) as amount')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->lot => bcadd((string) $r->qty, '0', 4).' @ '.bcadd((string) $r->amount, '0', 4)])
            ->all();

        $this->assertSame([(int) $this->newer->id => '10.0000 @ 38000.0000'], $drawn,
            '⛔ তাক থেকে LOT-NEW (৩,৮০০ দর) বেরোল, অথচ খরচ টানা হলো অন্য লটের স্তর থেকে — '
            .'খাতায় এক লটের দাম, গুদামে আরেক লটের মাল। (লটের আইডি → পরিমাণ @ টাকা: '
            .json_encode($drawn).'; LOT-OLD = '.$this->older->id.')');

        // ── পাঁচ মিল
        $this->assertBooksMatch($before, $this->books($this->customer, [(int) $this->product->id]), [
            'sales' => '40000',
            'cogs' => '38000',
            'received' => [(int) app(CashTillService::class)->ensurePrimaryTill()->account_id => '15000'],
            'stockOut' => [$this->key($this->newer) => '10'],
        ], 'লট বেছে বিক্রির পরে');

        // ⓘ বাকি স্তর = বাকি মাল, লট ধরে: OLD ১০০ × ৩,০০০ + NEW ৯০ × ৩,৮০০ = ৬,৪২,০০০
        $this->assertMoney('642000', $this->books($this->customer, [(int) $this->product->id])['layers'],
            '⛔ তাকে পড়ে থাকা মালের আসল দাম আর মজুদের স্তরের মূল্য আলাদা');
    }
}
