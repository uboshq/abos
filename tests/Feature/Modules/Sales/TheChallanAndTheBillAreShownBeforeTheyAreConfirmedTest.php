<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Approval\Services\OwnerSignsDiscounts;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\CreditExposure;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ রাখা চালান আর বিলের "নিশ্চিত করুন"-এর আগে সারাংশ — মালিক, ৪ অক্টোবর ২০২৬: *"sob kichutei"*
 * ([[SalesPaperOverview]], [[SalesPaperOverviewController]])।
 *
 * দাবি:
 *   একই মানুষ — নিশ্চিতের চাবি ছাড়া ৪০৩, চাবি আছে কিন্তু কাগজ দেখার অধিকার নেই তো ৪০৩, দুটো দিলে সারাংশ;
 *   সারাংশ কাগজের নিজের সারি আর মোট দেখায়, আর কিছুই লেখে না (অবস্থা খসড়াই, মজুদ নড়ে না);
 *   সীমা পার — "নিশ্চিত হবে না" আর দরজার নিজের কথা; একই কাগজে সীমার সুইচ বন্ধ করলে কিছুই থামে না;
 *   বিলে ছাড় আর সইয়ের ছক — "মালিকের সই লাগবে"; ছাড় না থাকলে সেই কথা নেই;
 *   চালান আর বিলের পাতা সারাংশের ঠিকানা, পপ-আপের খোলস আর "নিশ্চিত" বোতামের চিহ্ন বহন করে।
 */
final class TheChallanAndTheBillAreShownBeforeTheyAreConfirmedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '1000000'])->save();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->update(['track_batch' => false]);
    }

    public function test_the_same_seller_needs_the_key_and_the_right_to_see_the_paper(): void
    {
        $challan = $this->challan();
        $seller = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $seller->companies()->attach($this->company->id, ['is_active' => true]);

        $this->actingAs($seller->fresh())->post(route('sales.challan.overview', $challan))->assertForbidden();

        $this->grant($seller, 'sales.challan.create');
        $this->actingAs($seller->fresh())->post(route('sales.challan.overview', $challan))->assertForbidden();

        $this->grant($seller, 'sales.challan.view');
        $this->actingAs($seller->fresh())->post(route('sales.challan.overview', $challan))->assertOk();
    }

    public function test_the_challan_overview_reads_the_paper_and_writes_nothing(): void
    {
        $challan = $this->challan();
        $moves = StockMovement::query()->count();

        $this->post(route('sales.challan.overview', $challan))->assertOk()
            ->assertSee('data-overview-blocks="0"', false)
            ->assertSee($challan->document_no)
            ->assertSee($this->product->name())
            ->assertSee('1,200.00');

        $this->assertSame(DocumentStatus::DRAFT, $challan->fresh()->status, '⛔ সারাংশ দেখতে গিয়েই চালান নিশ্চিত হয়ে গেল।');
        $this->assertSame($moves, StockMovement::query()->count(), '⛔ সারাংশ দেখতে গিয়েই মজুদ নড়ল।');
    }

    public function test_over_the_limit_it_says_it_will_not_confirm_and_switched_off_it_does_not(): void
    {
        $this->customer->forceFill(['credit_limit' => '100'])->save();
        $invoice = $this->invoice();

        $this->post(route('sales.invoice.overview', $invoice))->assertOk()
            ->assertSee('data-overview-blocks="1"', false)
            ->assertSee('data-overview-note="stop"', false);

        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
        app()->forgetInstance(CreditExposure::class);

        $this->post(route('sales.invoice.overview', $invoice))->assertOk()
            ->assertSee('data-overview-blocks="0"', false)
            ->assertDontSee(__('sales::overview_confirm.limit'));
    }

    public function test_a_discount_on_the_bill_says_the_owner_will_sign(): void
    {
        app(OwnerSignsDiscounts::class)->ensure($this->company);

        $this->post(route('sales.invoice.overview', $this->invoice()))->assertOk()
            ->assertDontSee(__('sales::overview_confirm.discount_signature'));

        $this->post(route('sales.invoice.overview', $this->invoice(['discount' => '50'])))->assertOk()
            ->assertSee(__('sales::overview_confirm.discount_signature'));
    }

    public function test_both_pages_carry_the_popup_and_its_address(): void
    {
        $challan = $this->challan();
        $invoice = $this->invoice();

        $this->get(route('sales.challan.show', $challan))->assertOk()
            ->assertSee('data-confirm-overview="'.route('sales.challan.overview', $challan).'"', false)
            ->assertSee('data-confirm-overview-dialog', false)
            ->assertSee('data-overview-trigger', false);

        $this->get(route('sales.invoice.show', $invoice))->assertOk()
            ->assertSee('data-confirm-overview="'.route('sales.invoice.overview', $invoice).'"', false)
            ->assertSee('data-confirm-overview-dialog', false)
            ->assertSee('data-overview-trigger', false);
    }

    /**
     * ⭐ টাকা আদায় — মোট, আদায়ের পরের বকেয়া, কিছু লেখে না; আর দরজার নিজের পাহারা: নিয়মের আগে লেখা চেকের খসড়া
     * "নিশ্চিত হবে না" ([[CollectionService::whatWouldStopTheConfirm()]])।
     */
    public function test_the_collection_overview_shows_the_due_after_and_stops_an_old_cheque_draft(): void
    {
        $collection = app(CollectionService::class)->create([
            'customer_id' => $this->customer->id, 'trx_date' => now()->toDateString(), 'amount' => '500', 'instrument' => 'cash',
        ], [])->fresh();

        $this->post(route('sales.collection.overview', $collection))->assertOk()
            ->assertSee('data-overview-blocks="0"', false)
            ->assertSee(__('sales::overview_confirm.collection_total'))
            ->assertSee('500.00');
        $this->assertSame(DocumentStatus::DRAFT, $collection->fresh()->status, '⛔ সারাংশ দেখতে গিয়েই আদায় নিশ্চিত হয়ে গেল।');

        // ⓘ চেক কেবল চেকের খাতা দিয়ে — নিয়মের আগে লেখা খসড়াতেও দরজা থামায়, সারাংশও আগেই বলে
        $collection->forceFill(['instrument' => 'cheque'])->save();

        $this->post(route('sales.collection.overview', $collection))->assertOk()
            ->assertSee('data-overview-blocks="1"', false)
            ->assertSee(__('accounts::validation.cheque_only_through_register'));

        $this->get(route('sales.collection.show', $collection))->assertOk()
            ->assertSee('data-confirm-overview="'.route('sales.collection.overview', $collection).'"', false)
            ->assertSee('data-confirm-overview-dialog', false)
            ->assertSee('data-overview-trigger', false);
    }

    /**
     * ⭐ বিক্রয় আদেশ — দুই পথ ([[SalesOrderService::whatTheConfirmWouldDo()]]):
     *   সুইচ বন্ধ: মাল আটকানোর আগে মজুদ নেই → "নিশ্চিত হবে না";
     *   সুইচ চালু: "নিশ্চিত" মানে জমা — সীমা পার হলেও থামে না, বলে "টাকার অপেক্ষায় থাকবে" (`warn`, blocks নয়);
     *   আর কিছুই লেখা হয় না (খসড়াই থাকে), পাতায় পপ-আপ বসানো।
     */
    public function test_the_order_overview_knows_both_paths_and_writes_nothing(): void
    {
        app(SettingsService::class)->set('sales.reserve_on_order', true);
        app(SettingsService::class)->set(\App\Modules\Sales\Services\SalesOrderService::REPLACES_DO, false);
        app(\App\Modules\Sales\Services\SalesOrderService::class)->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'ordered_qty' => '100000', 'rate' => '10']],
        );
        $order = \App\Modules\Sales\Models\SalesOrder::query()->latest('id')->firstOrFail();

        // ⛔ মজুদের চেয়ে বেশি — পুরনো নিশ্চিতের দরজা থামাত, সারাংশ আগেই বলে
        $this->post(route('sales.order.overview', $order))->assertOk()
            ->assertSee('data-overview-blocks="1"', false)
            ->assertSee($order->document_no);

        app(SettingsService::class)->set(\App\Modules\Sales\Services\SalesOrderService::REPLACES_DO, true);
        app()->forgetInstance(\App\Modules\Sales\Services\SalesOrderService::class);
        $this->customer->forceFill(['credit_limit' => '100'])->save();

        $this->post(route('sales.order.overview', $order))->assertOk()
            ->assertSee('data-overview-blocks="0"', false)
            ->assertSee(__('sales::overview_confirm.order_submits'))
            ->assertSee('data-overview-note="warn"', false);

        $this->assertSame(DocumentStatus::DRAFT, $order->fresh()->status, '⛔ সারাংশ দেখতে গিয়েই আদেশ জমা হয়ে গেল।');

        $this->get(route('sales.order.show', $order))->assertOk()
            ->assertSee('data-confirm-overview="'.route('sales.order.overview', $order).'"', false)
            ->assertSee('data-overview-trigger', false);
    }

    private function challan(): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'delivered_qty' => '12', 'rate' => '100']],
        );
    }

    /** @param  array<string, mixed>  $line */
    private function invoice(array $line = []): SalesInvoice
    {
        return app(SalesInvoiceService::class)->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '12', 'rate' => '100', ...$line]],
        );
    }

    private function grant(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id,
            fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
