<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Concerns\PrintsTheStandardPaper;
use Tests\TestCase;

/**
 * ⭐ গেট পাসে মাল বেরোনোর পথে ফ্রি মাল — মালিক, ১০ অক্টোবর ২০২৬: *"গেট পাসে আলাদা লাইনে 'ফ্রি' লেখা হয়ে যাবে, বিলে দাম ০;
 * মজুদ থেকে কাটবে, টাকার হিসাবে আসবে না"* (সুইচ `sales.invoice_at_goods_issue`; [[SalesPrintController::freeOnItsOwnLine()]])।
 *
 * ⛔ আগে গেট পাসে ফ্রি বসত বিক্রির সারির একটা ঘরে ("ফ্রি ১, মোট ৩") — গেটের লোক আলাদা করে গুনতে পেতেন না কোনটা দামি মাল আর কোনটা ফ্রি।
 * ⓘ সুইচ বন্ধের কাগজ আগের মতোই।
 */
final class TheFreeGoodsLeaveOnTheirOwnGateLineTest extends TestCase
{
    use PrintsTheStandardPaper;
    use RefreshDatabase;

    private Customer $customer;

    private Product $biscuit;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->printTheStandardPaper();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '1000000'])->save();
        $this->biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        // ⓘ ডেমোতে সরবরাহকারীর ফ্রি-ভাণ্ডার নেই — ফ্রি নিজের মাল থেকে ([[DirectSaleService::giveAway()]])
        app(SettingsService::class)->set('sales.free_beyond_pool', true);
    }

    public function test_the_free_goods_leave_the_shelf_at_the_gate_on_their_own_line_and_cost_the_buyer_nothing(): void
    {
        app(SettingsService::class)->set('sales.invoice_at_goods_issue', true);
        $floor = $this->floor();

        [$challan, $invoice] = $this->sell();
        $this->assertSame($floor, $this->floor(), 'দৃশ্যটাই বানানো যায়নি — গেটের আগেই তাক থেকে মাল কমেছে');

        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DISPATCHED);

        $this->assertSame(bcsub($floor, '3', 4), $this->floor(), '⛔ গেট পাসে ২ বিক্রি + ১ ফ্রি তাক থেকে কাটেনি');
        $invoice = $invoice->fresh();
        $this->assertSame('confirmed', $invoice->status);
        $this->assertSame('20.0000', (string) $invoice->total, '⛔ ফ্রি মালের দাম বিলে উঠেছে');

        $pass = GatePass::query()->where('delivery_challan_id', $challan->id)->firstOrFail();

        foreach (['sales.print.gate_pass' => $pass, 'sales.print.gatepass' => $challan] as $route => $model) {
            $rows = $this->rows(route($route, $model));

            $this->assertCount(2, $rows, "⛔ {$route}: ফ্রি মাল আলাদা লাইনে নেই");
            $this->assertSame(['2', '', '2'], [$rows[0]['qty'], $rows[0]['free'], $rows[0]['total_qty']], 'বিক্রির লাইনে কেবল বিক্রির পরিমাণ');
            $this->assertSame(__('sales::print.free_line', ['name' => $rows[0]['name']]), $rows[1]['name'], "⛔ {$route}: ফ্রি লাইনে \"ফ্রি\" লেখা নেই");
            $this->assertSame(['1', '0.00', '0.00'], [$rows[1]['qty'], $rows[1]['rate'], $rows[1]['amount']], 'ফ্রি লাইনে দাম ০');
        }
    }

    public function test_with_the_switch_off_the_gate_paper_keeps_its_old_single_line(): void
    {
        [$challan] = $this->sell();

        $rows = $this->rows(route('sales.print.gatepass', $challan));

        $this->assertCount(1, $rows);
        $this->assertSame(['2', '1', '3'], [$rows[0]['qty'], $rows[0]['free'], $rows[0]['total_qty']]);
    }

    /**
     * ⛔ কার্টনে বেচা সারির ফ্রি পিসে, কার্টনের ভগ্নাংশে নয় (১১ অক্টোবর ২০২৬, PR #17 রিভিউ ⚠️৬)।
     *
     * ⓘ ১২-পিসের কার্টনে ১ পিস ফ্রি আগে ছাপত "০.০৮৩৩ Ctn — ফ্রি" — গেটের লোক গুনতে পারতেন না। এখন ফ্রি লাইন ফ্রি-র নিজের ঘর
     * (পিসে, যেমন আগে ফ্রি-র কলামে) আর পিসের একক নেয়।
     */
    public function test_a_free_piece_on_a_carton_line_reads_as_one_piece(): void
    {
        $challan = (new DeliveryChallan)->forceFill(['issue_at_gate' => true]);
        $rows = [['name' => 'Biscuit', 'code' => '', 'qty' => '1', 'unit' => 'Ctn', 'free' => '1', 'free_unit' => 'Pcs',
            'total_qty' => '1.0833', 'rate' => '1,200.00', 'amount' => '1,200.00']];

        $out = (new \ReflectionMethod(\App\Modules\Sales\Http\Controllers\SalesPrintController::class, 'freeOnItsOwnLine'))
            ->invoke(app(\App\Modules\Sales\Http\Controllers\SalesPrintController::class), $challan, $rows);

        $this->assertCount(2, $out);
        $this->assertSame(['1', 'Ctn', '1'], [$out[0]['qty'], $out[0]['unit'], $out[0]['total_qty']], 'বিক্রির লাইন কার্টনেই');
        $this->assertSame(['1', 'Pcs', '1'], [$out[1]['qty'], $out[1]['unit'], $out[1]['total_qty']],
            '⛔ ফ্রি লাইন কার্টনের ভগ্নাংশে — গেটে গোনা যায় না।');
    }

    /** @return array{0: DeliveryChallan, 1: SalesInvoice} */
    private function sell(): array
    {
        $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(),
            'payment_term' => 'credit', 'vehicle_owner' => 'customer', 'delivery_mode' => 'send_later',
            'ship_to' => 'কাপ্তান বাজার', 'ship_date' => now()->addDay()->toDateString(),
            'lines' => [['product_id' => $this->biscuit->id, 'qty' => '2', 'rate' => '10', 'free_qty' => '1']],
        ])->assertSessionHasNoErrors();

        return [DeliveryChallan::query()->latest('id')->firstOrFail(), SalesInvoice::query()->latest('id')->firstOrFail()];
    }

    /** @return list<array<string, string>> */
    private function rows(string $url): array
    {
        $doc = null;
        View::composer('print.*', function ($view) use (&$doc) {
            $doc ??= $view->getData()['doc'] ?? null;
        });

        $this->get($url)->assertOk();
        $this->assertNotNull($doc, 'ছাঁচ ডাকাই হয়নি');

        return array_values((array) $doc->lines);
    }

    private function floor(): string
    {
        return bcadd((string) StockMovement::query()->where('product_id', $this->biscuit->id)->where('warehouse_id', $this->warehouse->id)->sum('floor_change'), '0', 4);
    }
}
