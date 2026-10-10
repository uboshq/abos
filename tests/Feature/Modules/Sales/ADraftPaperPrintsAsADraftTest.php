<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Http\Controllers\VoucherPrintController;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use ReflectionMethod;
use Tests\Concerns\PrintsTheStandardPaper;
use Tests\TestCase;

/**
 * ⛔ খসড়া কাগজ খসড়া হয়েই ছাপা হয় — চালান, গেটপাস, আদেশ, ভাউচার আর ক্রয়ের কাগজ (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, ছাপা ১০)।
 *
 * ⓘ "খসড়া" বাক্স আর জলছাপ ছিল কেবল আদায়ের রসিদে ([[ADraftReceiptSaysItIsADraftTest]])। খসড়া চালান বা তার গেটপাস হুবহু পাকা কাগজের মতো
 * বেরোত — দেখিয়ে গেট থেকে মাল বের করা যেত; খসড়া ভাউচার পাকা রসিদের মতো।
 */
final class ADraftPaperPrintsAsADraftTest extends TestCase
{
    use PrintsTheStandardPaper;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->printTheStandardPaper();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_a_draft_challan_its_gate_pass_and_a_draft_order_say_draft(): void
    {
        $challan = app(DeliveryChallanService::class)->create(['customer_id' => $this->customer()->id, 'warehouse_id' => $this->warehouse()->id,
            'trx_date' => now()->toDateString(), 'own_transport' => true, 'vehicle_no' => 'DHA-GA-11-2233'],
            [['product_id' => $this->product()->id, 'delivered_qty' => '1', 'rate' => '10']]);

        foreach (['sales.print.challan', 'sales.print.gatepass'] as $route) {
            $this->assertStringContainsString(__('core.print.draft_paper_notice'), $this->html(route($route, $challan), 'print.*'),
                "⛔ খসড়া চালানের কাগজ ({$route}) পাকা কাগজের মতো ছাপা হলো");
        }
        $this->assertSame(__('core.print.draft_watermark'), $this->salesWatermark($challan->fresh()), '⛔ খসড়া চালানে জলছাপ নেই');

        $order = app(SalesOrderService::class)->create(['customer_id' => $this->customer()->id, 'warehouse_id' => $this->warehouse()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product()->id, 'ordered_qty' => '2', 'rate' => '10']]);
        $this->assertStringContainsString(__('core.print.draft_paper_notice'), $this->html(route('sales.print.order', $order), 'print.*'),
            '⛔ খসড়া আদেশ পাকা আদেশের মতো ছাপা হলো');
        $this->assertSame(__('core.print.draft_watermark'), $this->salesWatermark($order->fresh()));
    }

    public function test_a_draft_voucher_and_a_draft_purchase_order_say_draft(): void
    {
        $cash = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $income = (int) Account::query()->postable()->active()->where('type', Account::INCOME)->value('id');
        $voucher = app(VoucherService::class)->create(['type' => 'receipt', 'trx_date' => now()->toDateString(), 'narration' => 'Draft receipt'],
            [['account_id' => $cash, 'debit' => '100', 'credit' => '0'], ['account_id' => $income, 'debit' => '0', 'credit' => '100']]);
        $this->assertTrue($voucher->fresh()->isDraft(), 'দৃশ্যটাই বানানো যায়নি');

        $seen = [];
        View::composer('print.*', function ($view) use (&$seen) {
            $seen = $view->getData();
        });
        $this->get(route('accounts.voucher.print', $voucher))->assertOk();
        $this->assertSame(__('core.print.draft_paper_notice'), $seen['notice'] ?? null, '⛔ খসড়া ভাউচার পাকা রসিদের মতো ছাপা হলো');
        $this->assertSame(__('core.print.draft_watermark'), (new ReflectionMethod(VoucherPrintController::class, 'watermarkFor'))
            ->invoke(app(VoucherPrintController::class), Voucher::query()->findOrFail($voucher->id)));

        $order = app(PurchaseOrderService::class)->create(['supplier_id' => Supplier::query()->orderBy('id')->value('id'),
            'warehouse_id' => $this->warehouse()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product()->id, 'ordered_qty' => '10', 'rate' => '100']]);
        $this->assertStringContainsString(__('core.print.draft_paper_notice'), $this->html(route('purchase.print.order', $order), 'print.*'),
            '⛔ খসড়া ক্রয় আদেশ পাকা কাগজের মতো ছাপা হলো');
    }

    /** ⓘ ছাঁচের হাতে যাওয়া কাগজের বার্তাগুলো */
    private function html(string $url, string $views): string
    {
        $notice = null;
        View::composer($views, function ($view) use (&$notice) {
            $doc = $view->getData()['doc'] ?? null;

            if (is_object($doc) && property_exists($doc, 'notice')) {
                $notice = (string) $doc->notice;
            }
        });

        $this->get($url)->assertOk();
        $this->assertNotNull($notice, 'ছাঁচ ডাকাই হয়নি');

        return $notice;
    }

    private function salesWatermark(object $document): ?string
    {
        return (new ReflectionMethod(SalesPrintController::class, 'watermarkFor'))->invoke(app(SalesPrintController::class), $document);
    }

    private function customer(): Customer
    {
        return Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    private function product(): Product
    {
        return Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
    }
}
