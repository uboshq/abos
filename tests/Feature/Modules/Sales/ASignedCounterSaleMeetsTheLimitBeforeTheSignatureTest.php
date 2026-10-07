<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
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
use App\Modules\Sales\Models\PricingRule;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\MarginGuard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * সইয়ের পথে কাউন্টারের বিক্রি — সীমার "না" সই চাওয়ার **আগে**। ৩ অক্টোবর ২০২৬ (abos-bb-এর ধরা; [[DirectSaleService::hold()]])।
 *
 * ⓘ জমার সই বা মার্জিনের আগাম অনুমোদন লাগলে বিক্রি সরাসরি সইয়ের পথে যায়, আর আগে দেয়াল আসত কেবল শেষ সইয়ের পরে —
 * সীমা পার হওয়া বিক্রিও সইয়ের সারিতে দাঁড়াত। নিজের পণ্য, জানা খরচ ৯৬, বেচা ৯০ — খরচের নিচে, মার্জিনের নিয়ম "অনুমোদন"
 * আর ছক বসানো, তাই বিক্রি ঐ পথেই যায়। ⚠️ প্রথম দাবিটা সেটাই প্রমাণ করে — সীমা বড় হলে বিক্রি সইয়ের সারিতে যায়; ঐ দাবি
 * না থাকলে বিক্রি অন্য পথে গিয়ে অন্য দেয়ালে আটকালেও দ্বিতীয় দাবি সবুজ হত (৩ অক্টোবর, একটা mutant ঠিক এভাবেই বেঁচে গিয়েছিল)।
 *
 * ⭐ একই মানুষ, একই বিক্রি: সীমা ০, কোনো জমা নেই →
 *   · "খসড়া রাখুন" চলে — মালিকের ধারা §৫: টাকা আসতে দেরি হলে খসড়া;
 *   · নিশ্চিত চাইলে `customer_id`-এ আটকায়, আর কোনো সইয়ের অনুরোধ জন্মায় না।
 */
final class ASignedCounterSaleMeetsTheLimitBeforeTheSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        // ⓘ খরচের নিচে বেচা মানেই মার্জিনের আগাম অনুমোদনের পথ — ছকসহ; দামের নীতি চুপ
        app(SettingsService::class)->set(MarginGuard::ACTION, MarginGuard::APPROVAL);
        app(SettingsService::class)->set(MarginGuard::FLOOR, '0');
        app(SettingsService::class)->set(PricingRule::POLICY, PricingRule::ALLOW);

        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(), 'module' => 'sales', 'action' => MarginGuard::APPROVAL_ACTION,
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user',
            'approver_id' => User::query()->where('email', 'owner@abos.test')->value('id'),
        ]);

        $this->product = app(ProductService::class)->create([
            'name_en' => 'Sign Wall Rice', 'name_bn' => 'Sign Wall Rice',
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'purchase_price' => '1', 'sale_price' => '1', 'reorder_level' => '0',
        ]);
        app(CostLayerService::class)->receive(product: $this->product, qty: '10', unitCost: '96', sourceType: 'opening', sourceId: $this->product->id, documentNo: 'OPENING');
        app(StockService::class)->move(
            product: $this->product, warehouse: Warehouse::query()->where('is_default', true)->firstOrFail(),
            sourceType: 'opening', sourceId: $this->product->id, floor: '10',
        );

        $this->customer = Customer::query()->create(['code' => 'SIGN-WALL', 'name_en' => 'Sign Wall', 'name_bn' => 'Sign Wall', 'is_active' => true]);
        $this->customer->forceFill(['credit_limit' => '0'])->save();
    }

    /** ⭐ পথের প্রমাণ — সীমা বড় হলে একই বিক্রি সইয়ের সারিতে যায়, অর্থাৎ এটা সত্যিই সইয়ের পথ। */
    public function test_with_room_the_same_sale_goes_to_the_signature(): void
    {
        $this->customer->forceFill(['credit_limit' => '1000000'])->save();
        $approvals = Approval::query()->where('action', MarginGuard::APPROVAL_ACTION)->count();

        $this->assertNull($this->refusedField(fn () => $this->sell([])), 'সীমা বড়, অথচ আটকেছে — প্রস্তুতিটাই ভুল।');
        $this->assertSame($approvals + 1, Approval::query()->where('action', MarginGuard::APPROVAL_ACTION)->count(),
            'প্রস্তুতিটাই ভুল — বিক্রি মার্জিনের সইয়ের পথে যায়নি, তাই নিচের দাবি কিছুই মাপত না।');
    }

    public function test_a_draft_may_wait_for_money_but_a_signed_sale_is_refused_before_anyone_is_asked(): void
    {
        $this->assertNull($this->refusedField(fn () => $this->sell(['save_as_draft' => '1'])),
            '⛔ সীমা ০, জমা নেই — অথচ "খসড়া রাখুন"-ও আটকেছে; টাকা আসতে দেরি হলে খসড়া রাখার কথা।');

        $draft = SalesInvoice::query()->where('customer_id', $this->customer->id)->latest('id')->first();
        $this->assertNotNull($draft, 'প্রস্তুতিটাই ভুল — খসড়াটা নেই।');

        $approvals = Approval::query()->count();

        $this->assertSame('customer_id', $this->refusedField(fn () => $this->sell(['resume_invoice_id' => (string) $draft->id])),
            '⛔ সীমা ০, জমা নেই — অথচ নিশ্চিতের পথে আটকায়নি।');

        $this->assertSame($approvals, Approval::query()->count(), '⛔ সীমা পার হওয়া বিক্রি সইয়ের সারিতে গেছে — "না" আসার কথা সইয়ের আগে।');
    }

    /** @param  array<string, string>  $extra */
    private function sell(array $extra): void
    {
        app(DirectSaleService::class)->complete(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                ...$extra,
            ],
            [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '90']],
        );
    }

    private function refusedField(callable $act): ?string
    {
        try {
            $act();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        return null;
    }
}
