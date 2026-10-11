<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Concerns\PrintsTheStandardPaper;
use Tests\TestCase;

/**
 * ⛔ দ্বিতীয় ছাপায় DUPLICATE — গেটপাস, রসিদ, আদেশ আর DO-তেও, বিল আর চালানের মতো (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, ছাপা ১৩;
 * [[SalesPrintController::pdf()]])।
 *
 * ⓘ এই কাগজগুলো ছাপার গোনায় ঢুকত না, তাই দ্বিতীয় কপি হুবহু প্রথমটার মতো — দুইটা গেটপাসে দুইবার মাল বের করা যেত।
 */
final class EveryReprintSaysDuplicateTest extends TestCase
{
    use PrintsTheStandardPaper;
    use RefreshDatabase;

    public function test_the_second_print_of_each_paper_carries_duplicate(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->printTheStandardPaper();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $customer->forceFill(['credit_limit' => '100000000'])->save();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->where('track_batch', false)->where('is_active', true)->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: StockService::ADJUSTMENT, sourceId: $product->id, floor: '100');

        $challans = app(DeliveryChallanService::class);
        $challan = $challans->confirm($challans->create(['customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
            'trx_date' => now()->toDateString(), 'own_transport' => true, 'vehicle_no' => 'DHA-GA-11-2233'],
            [['product_id' => $product->id, 'delivered_qty' => '2', 'rate' => '100']])->fresh(['lines']));
        // ⓘ রওনা — আসল গেট পাস ([[GatePassService]]); গেটপাস-চালানের ছাপা আগে, রওনার আগে
        $gateFromChallan = route('sales.print.gatepass', $challan);
        $firstFromChallan = $this->notice($gateFromChallan);
        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DISPATCHED);
        $pass = GatePass::query()->where('delivery_challan_id', $challan->id)->firstOrFail();
        $orders = app(SalesOrderService::class);
        $order = $orders->create(['customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'ordered_qty' => '2', 'rate' => '100']]);
        $receipt = app(CollectionService::class)->create(['customer_id' => $customer->id, 'trx_date' => now()->toDateString(), 'amount' => '500', 'instrument' => 'cash'], []);

        /*
         * ⛔ খসড়া ছাপা গোনায় পড়ে না (১১ অক্টোবর ২০২৬, PR #17 রিভিউ ⚠️১০) — খসড়া অর্ডার আর রসিদ মিলিয়ে দেখতে দুইবার করে ছাপা,
         * তারপর নিশ্চিত। নিচের লুপের "প্রথম ছাপায় DUPLICATE নয়" এখন এটাও মাপে: খসড়া গুনলে পাকা কাগজের প্রথম ছাপাই নকল বলত।
         */
        foreach ([route('sales.print.order', $order), route('sales.print.receipt', $receipt)] as $draftUrl) {
            $this->assertStringNotContainsString('DUPLICATE', $this->notice($draftUrl));
            $this->assertStringNotContainsString('DUPLICATE', $this->notice($draftUrl), '⛔ খসড়ার দ্বিতীয় ছাপা গোনা হয়েছে।');
        }
        $order = $orders->confirm($order->fresh(['lines']))->fresh(['lines']);
        $receipt = app(CollectionService::class)->confirm($receipt->fresh());

        $this->assertStringNotContainsString('DUPLICATE', $firstFromChallan);
        $this->assertStringContainsString(__('core.print.duplicate_notice', ['n' => 2]), $this->notice($gateFromChallan),
            '⛔ চালানের গেটপাস: দ্বিতীয় ছাপা হুবহু প্রথমটার মতো — DUPLICATE নেই');

        foreach ([
            'গেট পাস' => route('sales.print.gate_pass', $pass),
            'আদেশ' => route('sales.print.order', $order),
            'ডিও' => route('sales.print.delivery_order', $order),
            'রসিদ' => route('sales.print.receipt', $receipt),
        ] as $paper => $url) {
            $first = $this->notice($url);
            $second = $this->notice($url);

            $this->assertStringNotContainsString('DUPLICATE', $first, "{$paper}: প্রথম ছাপায় DUPLICATE নয়");
            $this->assertStringContainsString(__('core.print.duplicate_notice', ['n' => 2]), $second, "⛔ {$paper}: দ্বিতীয় ছাপা হুবহু প্রথমটার মতো — DUPLICATE নেই");
        }
    }

    private function notice(string $url): string
    {
        $notice = null;
        View::composer('print.*', function ($view) use (&$notice) {
            $doc = $view->getData()['doc'] ?? null;

            if (is_object($doc) && property_exists($doc, 'notice')) {
                $notice = (string) $doc->notice;
            }
        });

        $this->get($url)->assertOk();
        $this->assertNotNull($notice, 'ছাঁচ ডাকাই হয়নি: '.$url);

        return $notice;
    }
}
