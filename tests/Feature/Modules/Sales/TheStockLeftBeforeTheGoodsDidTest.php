<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
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
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * মজুদ কমত মাল বেরোনোর আগেই — মালিক, ৪ অক্টোবর ২০২৬: *"অবশ্যই ইন্টারন্যাশনাল স্ট্যান্ডার্ড"* (SAP-এর Post Goods Issue, IFRS ১৫)।
 *
 * ⭐ সুইচ `sales.invoice_at_goods_issue` (ডিফল্ট বন্ধ): চালু থাকলে গাড়িতে যাওয়া বিক্রির চালান নিশ্চিতে মাল কেবল আটকায় (তাক
 * অপরিবর্তিত, "পাওয়া যায়" কমে), বিল খসড়ায় চালানে বাঁধা; গেট পাসে মাল বেরোয়, খরচ ওঠে, বিল একই নম্বরে পাকা হয়।
 * "এখনই নিয়ে যাবেন" এক চাপে সব। বন্ধ থাকলে আজকের আচরণ, হুবহু।
 */
final class TheStockLeftBeforeTheGoodsDidTest extends TestCase
{
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
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '1000000'])->save();
        $this->biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    /** ⭐ একই মানুষ, একই ক্রেতা: সুইচ বন্ধে আজকের মতো (নিশ্চিতেই মাল বেরোয়, বিল পাকা); চালুতে নিশ্চিতে কেবল আটকায় */
    public function test_confirm_only_holds_the_goods_while_the_switch_is_on(): void
    {
        $floor = $this->floor();

        [$challan, $invoice] = $this->sell('send_later');
        $this->assertSame(bcsub($floor, '2', 4), $this->floor(), 'সুইচ বন্ধ: নিশ্চিতেই মাল বেরোয়নি — আজকের আচরণ ভেঙেছে।');
        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status);
        $this->assertFalse((bool) $challan->issue_at_gate);

        app(SettingsService::class)->set('sales.invoice_at_goods_issue', true);
        $floor = $this->floor();
        $reserved = $this->reserved();

        [$challan, $invoice] = $this->sell('send_later');

        $this->assertTrue((bool) $challan->issue_at_gate);
        $this->assertSame(DocumentStatus::CONFIRMED, $challan->status, 'চালান নিশ্চিত — মাল আটকানো।');
        $this->assertSame($floor, $this->floor(), '⛔ গেট পাসের আগেই তাক থেকে মাল কমেছে।');
        $this->assertSame(bcadd($reserved, '2', 4), $this->reserved(), '⛔ মাল আটকায়নি — অন্য কেউ বেচে দিতে পারত।');
        $this->assertSame(DocumentStatus::DRAFT, $invoice->status, '⛔ মাল বেরোনোর আগেই বিল পাকা।');
        $this->assertSame(0, $this->passes($challan));
        $this->assertSame('0', $this->booked($invoice), '⛔ মাল বেরোনোর আগেই খাতায় আয়/খরচ।');
    }

    /** ⭐ গেট পাসে মাল বেরোয়, আটকানো ছাড়ে, বিল একই নম্বরে পাকা, খরচ ওঠে; দ্বিতীয় রওনা দুইবার বের করে না */
    public function test_the_gate_pass_issues_the_goods_and_the_invoice_on_the_same_number(): void
    {
        app(SettingsService::class)->set('sales.invoice_at_goods_issue', true);
        $floor = $this->floor();
        $reserved = $this->reserved();

        [$challan, $invoice] = $this->sell('send_later');

        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DISPATCHED);

        $invoice = $invoice->fresh();
        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status, '⛔ গেট পাসে বিল পাকা হয়নি।');
        $this->assertSame($this->tail($challan->fresh()->document_no), $this->tail($invoice->document_no), '⛔ বিল আর চালানের নম্বর আলাদা।');
        $this->assertSame(bcsub($floor, '2', 4), $this->floor(), '⛔ গেট পাসে মাল বেরোয়নি।');
        $this->assertSame($reserved, $this->reserved(), '⛔ আটকানো ছাড়েনি — মাল দুইবার আটকে রইল।');
        $this->assertSame(1, $this->passes($challan));
        $this->assertNotSame('0', $this->booked($invoice), '⛔ পাকা বিলের খাতা নেই।');
        $this->assertNotNull($challan->fresh()->goods_issued_at);

        // ⓘ পৌঁছায়নি, আবার পাঠানো — মাল দুইবার বেরোয় না
        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::FAILED, ['note' => 'দোকান বন্ধ ছিল']);
        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DISPATCHED);
        $this->assertSame(bcsub($floor, '2', 4), $this->floor(), '⛔ দ্বিতীয় রওনায় মাল আবার বেরিয়েছে।');
    }

    /** "এখনই নিয়ে যাবেন" — সুইচ চালু থাকলেও এক চাপে সব: মাল বেরোয়, বিল পাকা, গেট পাস, পৌঁছেছে */
    public function test_taken_now_does_everything_in_one_press_even_with_the_switch_on(): void
    {
        app(SettingsService::class)->set('sales.invoice_at_goods_issue', true);
        $floor = $this->floor();

        [$challan, $invoice] = $this->sell('take_now');

        $this->assertFalse((bool) $challan->issue_at_gate);
        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->fresh()->status);
        $this->assertSame(bcsub($floor, '2', 4), $this->floor());
        $this->assertSame(1, $this->passes($challan));
    }

    /** গেট পাসের আগে বাতিল — আটকানো ফেরে, তাকে কিছু নড়ে না */
    public function test_cancelling_before_the_gate_releases_the_hold_and_touches_no_shelf(): void
    {
        app(SettingsService::class)->set('sales.invoice_at_goods_issue', true);
        $floor = $this->floor();
        $reserved = $this->reserved();

        [$challan, $invoice] = $this->sell('pickup_later');

        app(SalesInvoiceService::class)->cancel($invoice->fresh(), 'ক্রেতা আর নেবেন না');
        app(DeliveryChallanService::class)->cancel($challan->fresh(), 'ক্রেতা আর নেবেন না');

        $this->assertSame($reserved, $this->reserved(), '⛔ বাতিলের পরেও মাল আটকে আছে।');
        $this->assertSame($floor, $this->floor(), '⛔ বের-না-হওয়া মাল বাতিলে তাকে "ফিরল"।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array{0: DeliveryChallan, 1: SalesInvoice} */
    private function sell(string $mode): array
    {
        $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'payment_term' => 'credit',
            'vehicle_owner' => 'customer',
            'delivery_mode' => $mode,
            'ship_to' => 'কাপ্তান বাজার',
            'ship_date' => now()->addDay()->toDateString(),
            'lines' => [['product_id' => $this->biscuit->id, 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
        ])->assertSessionHasNoErrors();

        return [DeliveryChallan::query()->latest('id')->firstOrFail(), SalesInvoice::query()->latest('id')->firstOrFail()];
    }

    private function floor(): string
    {
        return bcadd((string) StockMovement::query()->where('product_id', $this->biscuit->id)->where('warehouse_id', $this->warehouse->id)->sum('floor_change'), '0', 4);
    }

    private function reserved(): string
    {
        return bcadd((string) StockMovement::query()->where('product_id', $this->biscuit->id)->where('warehouse_id', $this->warehouse->id)->sum('reserved_change'), '0', 4);
    }

    private function passes(DeliveryChallan $challan): int
    {
        return GatePass::query()->where('delivery_challan_id', $challan->id)->where('status', '<>', GatePass::CANCELLED)->count();
    }

    private function booked(SalesInvoice $invoice): string
    {
        return (string) DB::table('ledger_entries')->where('source_type', SalesInvoice::drillSourceType())->where('source_id', $invoice->id)->count();
    }

    /** "INV-0154" আর "CHA-0154"-এর নম্বর-অংশ */
    private function tail(string $no): string
    {
        return (string) preg_replace('/^[A-Z]+-/', '', $no);
    }
}
