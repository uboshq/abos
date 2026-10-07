<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\PricingRule;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\MarginGuard;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SignsTheDiscountAsTheOwner;
use Tests\TestCase;

/**
 * বিলের মাথার ছাড় খরচের নিচের দেয়াল এড়িয়ে যেত — চূড়ান্ত অডিট ⛔৬, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ আগে ([[MarginGuard::judge()]]) ─────────────────────────────────────
 * বিল মাপা হত মাথার ছাড় **শূন্য** ধরে — `bill_discount` বাদ। আর চালানে একবার পাশ করা সারি বিলে আর
 * মাপাই হত না। ফলে ৯৬ টাকা খরচের মাল চালানে ১০০-তে পাশ করিয়ে বিলে ২০ টাকা মাথার ছাড় দিলে ৮০-তে বিক্রি
 * — কোনো দেয়াল, কোনো সতর্কতা, কোনো সই ছাড়া।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * বিলের মাথার ছাড় বিলের মাপে ঢোকে; আর চালান যতটুকু মাথার ছাড় নিয়ে মাপা হয়েছিল বিলে তার বেশি হলে সব সারি
 * আবার মাপা হয়। ⓘ কাউন্টারে চালান আর বিলের মাথার ছাড় একই — সেখানে দ্বিতীয়বার মাপা বা দ্বিতীয় সই নয়।
 * ⭐ সব দাবি মালিকের (super_admin) হাতে — "সতর্ক"-এ তাঁর বিল আগের মতোই পাকা হয়, দেয়াল কেবল কোম্পানির
 * নিজের "আটকান" নিয়মে।
 */
final class TheBillDiscountSlippedUnderTheCostTest extends TestCase
{
    use RefreshDatabase;
    use SignsTheDiscountAsTheOwner;

    private Customer $customer;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->assertTrue($owner->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE), 'দৃশ্যটাই বানানো যায়নি — মালিক super_admin নন।');
        $this->actingAs($owner);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        // ⓘ অন্য দেয়াল চুপ — এই ফাইল কেবল মার্জিন মাপে
        $this->setting('customer.credit_limit_enabled', false);
        $this->setting(PricingRule::POLICY, PricingRule::ALLOW);
        $this->setting(MarginGuard::FLOOR, '0');
    }

    /** ⛔ চালানে ১০০-তে পাশ (খরচ ৯৬), বিলে ২০ টাকা মাথার ছাড় → ৮০ — আটকায়, বিল খসড়া থাকে। */
    public function test_a_bill_discount_after_the_challan_is_judged(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);
        $rice = $this->aProduct('Bill Discount Rice', '96');

        $bill = $this->billFromAChallan($rice, '100', '20');

        $errors = $this->refused(fn () => app(SalesInvoiceService::class)->confirm($bill->fresh()))->errors();

        $this->assertArrayHasKey('lines', $errors, '⛔ অন্য কারণে থেমেছে: '.json_encode($errors, JSON_UNESCAPED_UNICODE));
        $this->assertSame(DocumentStatus::DRAFT, $bill->fresh()->status, '⛔ থেমেও বিলটা পাকা হয়ে গেছে।');
    }

    /** ⛔ চালান ছাড়া বিল — ১০০ দর, ২০ মাথার ছাড়, খরচ ৯৬ — আটকায়। */
    public function test_a_bill_without_a_challan_counts_its_bill_discount(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);
        $rice = $this->aProduct('Bill Discount Rice Alone', '96');

        $bill = $this->discountSigned(app(SalesInvoiceService::class)->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'bill_discount' => '20',
        ], [['product_id' => $rice->id, 'qty' => '1', 'rate' => '100']]));

        $this->assertArrayHasKey('lines', $this->refused(fn () => app(SalesInvoiceService::class)->confirm($bill->fresh()))->errors());
        $this->assertSame(DocumentStatus::DRAFT, $bill->fresh()->status);
    }

    /** ⭐ পাল্টা-দাবি: খরচের উপরে থাকা মাথার ছাড় (১০০ − ২ = ৯৮ > ৯৬) পার হয় — দেয়াল কেবল ক্ষতি ধরে। */
    public function test_a_small_bill_discount_above_the_cost_passes(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);
        $rice = $this->aProduct('Bill Discount Rice Small', '96');

        $bill = $this->billFromAChallan($rice, '100', '2');

        app(SalesInvoiceService::class)->confirm($bill->fresh());

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->fresh()->status);
    }

    /**
     * ⭐ কাউন্টারে মাথার ছাড় চালানেই মাপা হয় — বিলে দ্বিতীয়বার নয়: "আটকান"-এও খরচের উপরের বিক্রি পাকা হয়,
     * আর "সতর্ক"-এ (ডিফল্ট) খরচের নিচেরটাও মালিকের হাতে আগের মতোই পাকা — কেবল সতর্কতা।
     */
    public function test_the_counter_is_judged_once_and_warn_never_blocks_the_owner(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::BLOCK);
        $rice = $this->aProduct('Bill Discount Rice Counter', '96');

        $this->assertSame(DocumentStatus::CONFIRMED, $this->sellAtTheCounter($rice, '100', '2')->status);

        $this->setting(MarginGuard::ACTION, MarginGuard::WARN);
        $bill = $this->billFromAChallan($this->aProduct('Bill Discount Rice Warn', '96'), '100', '20');

        app(SalesInvoiceService::class)->confirm($bill->fresh());

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->fresh()->status, '⛔ "সতর্ক"-এ মালিকের বিল আটকে গেছে।');
    }

    /**
     * ⭐ কাউন্টারে "অনুমোদন": ১০০ দর, ১০ মাথার ছাড় → ৯০ < ৯৬ — চালানে একবার সই চায়; মালিক (super_admin)
     * সই দিলেই বিক্রি শেষ, আর বিলে **দ্বিতীয় সই চাওয়া হয় না**।
     *
     * ⛔ চালান যে মাথার ছাড় নিয়ে মাপা হয়েছিল সেটা না গুনলে বিলটা আবার মাপা হত, নতুন সই চাইত, আর সই-দেওয়া
     * বিক্রিটা কাউন্টারে আটকে থাকত।
     */
    public function test_a_counter_bill_discount_is_signed_once_on_the_challan(): void
    {
        $this->setting(MarginGuard::ACTION, MarginGuard::APPROVAL);
        $this->marginFlow();
        $rice = $this->aProduct('Bill Discount Rice Signed', '96');

        $held = app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'deposit' => '0',
                'discount_amount' => '10'],
            [['product_id' => $rice->id, 'qty' => '1', 'rate' => '100']],
        );

        $this->assertTrue($held['margin_held'] ?? false, 'দৃশ্যটাই বানানো যায়নি — মাথার ছাড়ের বিক্রি সইয়ে যায়নি।');

        $approval = Approval::query()
            ->where('approvable_type', DeliveryChallan::class)
            ->where('approvable_id', $held['challan']->id)
            ->where('action', MarginGuard::APPROVAL_ACTION)
            ->firstOrFail();

        app(ApprovalEngine::class)->approve($approval, User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ যেকোনো ছাড়ে মালিকের সই (১ অক্টোবর ২০২৬) — এই দাবি হিসাব মাপে, সই নয় ([[SignsTheDiscountAsTheOwner]]) — মার্জিনের সই চালানে একবার, ছাড়ের সই আলাদা ছক
        $this->assertSame(1, $this->ownerSignsTheDiscounts(), 'দৃশ্যটাই বানানো যায়নি — ছাড়ের সই একসাথে চাওয়া হয়নি।');

        $this->assertSame(DocumentStatus::CONFIRMED, $held['invoice']->fresh()->status, '⛔ সইয়ের পরেও বিক্রিটা শেষ হয়নি।');
        $this->assertSame(0, Approval::query()
            ->where('approvable_type', SalesInvoice::class)
            ->where('action', MarginGuard::APPROVAL_ACTION)->count(), '⛔ চালানে মাপা ছাড়ের জন্য বিলে আবার সই চাওয়া হয়েছে।');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function marginFlow(): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => 'sales',
            'action' => MarginGuard::APPROVAL_ACTION,
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => User::query()->where('email', 'owner@abos.test')->value('id'),
        ]);
    }

    private function setting(string $key, mixed $value): void
    {
        app(SettingsService::class)->set($key, $value);
    }

    /** নিজের পণ্য, এক স্তর (১০ একক, খরচ `$cost`), মাল গুদামে। */
    private function aProduct(string $name, string $cost): Product
    {
        $product = app(ProductService::class)->create([
            'name_en' => $name,
            'name_bn' => $name,
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'purchase_price' => '1',
            'sale_price' => '1',
            'reorder_level' => '0',
        ]);

        app(CostLayerService::class)->receive(
            product: $product,
            qty: '10',
            unitCost: $cost,
            sourceType: 'opening',
            sourceId: $product->id,
            documentNo: 'OPENING',
        );

        app(StockService::class)->move(
            product: $product,
            warehouse: $this->warehouse,
            sourceType: 'opening',
            sourceId: $product->id,
            floor: '10',
        );

        return $product->fresh();
    }

    /** অফিসের চালান (১ একক, `$rate`), পাকা; তার বিল — মাথার ছাড় `$billDiscount`, খসড়া। */
    private function billFromAChallan(Product $product, string $rate, string $billDiscount): SalesInvoice
    {
        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $product->id, 'delivered_qty' => '1', 'rate' => $rate]]);

        $challan = app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));

        return $this->discountSigned(app(SalesInvoiceService::class)->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'bill_discount' => $billDiscount,
        ], [[
            'product_id' => $product->id,
            'delivery_challan_line_id' => $challan->fresh(['lines'])->lines->first()->id,
            'qty' => '1',
            'rate' => $rate,
        ]]));
    }

    /** যেকোনো ছাড়ে মালিকের সই (১ অক্টোবর ২০২৬) — এই দাবি হিসাব মাপে, সই নয় ([[SignsTheDiscountAsTheOwner]]) — মার্জিনের দেয়াল ছাড়ের সইয়ের পরে মাপা হয় */
    private function discountSigned(SalesInvoice $bill): SalesInvoice
    {
        try {
            app(SalesInvoiceService::class)->assertDiscountApproved($bill->fresh());
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount', $e->errors(), 'দৃশ্যটাই বানানো যায়নি: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
            $this->ownerSignsTheDiscounts();
        }

        return $bill->fresh();
    }

    private function sellAtTheCounter(Product $product, string $rate, string $billDiscount): SalesInvoice
    {
        $invoice = app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'deposit' => '0',
                'discount_amount' => $billDiscount],
            [['product_id' => $product->id, 'qty' => '1', 'rate' => $rate]],
        )['invoice'];

        // ⓘ যেকোনো ছাড়ে মালিকের সই (১ অক্টোবর ২০২৬) — এই দাবি হিসাব মাপে, সই নয় ([[SignsTheDiscountAsTheOwner]]) — শেষ সইয়ে বিক্রি নিজে শেষ
        $this->ownerSignsTheDiscounts();

        return $invoice->fresh();
    }

    private function refused(callable $work): ValidationException
    {
        try {
            $work();
        } catch (ValidationException $e) {
            return $e;
        }

        $this->fail('⛔ কিছুই আটকায়নি — মাথার ছাড়ে খরচের নিচের বিক্রি দিব্যি পার হয়ে গেল।');
    }
}
