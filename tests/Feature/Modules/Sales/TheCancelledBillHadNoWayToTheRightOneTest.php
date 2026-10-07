<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceCancellation;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বাতিলের পরে ঠিক বিলের কোনো পথ ছিল না — মালিকের বিক্রয় পরিকল্পনা §৬, ৬ অক্টোবর ২০২৬: "পুরো ভুল ইনভয়েস: বাতিল-ইনভয়েস,
 * তারপর ঠিক ইনভয়েস"।
 *
 * ⛔ বাতিল-ইনভয়েসের পাতা কেবল পুরনো বিলে ফিরত; ঠিক বিলটা শূন্য থেকে আবার লিখতে হত। ⭐ এখন কাউন্টারের বিলে "ঠিক বিল
 * বানান" — পুরনো সারিসহ কাউন্টার খোলে **নতুন** বিক্রি হিসেবে ([[DirectSaleController::reissueFrom()]]); সংরক্ষণে নতুন নম্বর,
 * পুরনোটা বাতিলই থাকে। আদেশ থেকে আসা বিলে পথটা আদেশে ফেরা।
 */
final class TheCancelledBillHadNoWayToTheRightOneTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $biscuit;

    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->data = [
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'own_transport' => '1',
        ];
    }

    public function test_a_cancelled_counter_bill_opens_its_lines_as_a_new_sale(): void
    {
        $wrong = $this->sell('3');
        $this->post(route('sales.invoice.cancellation', $wrong), ['reason' => 'পরিমাণ ভুল'])->assertSessionHas('saved');
        $paper = SalesInvoiceCancellation::query()->where('sales_invoice_id', $wrong->id)->firstOrFail();

        $page = $this->get(route('sales.cancellation.show', $paper))->assertOk();
        $page->assertSee(route('sales.direct.create', ['reissue' => $wrong->id]), false)->assertSee('data-reissue', false);
        $page->assertDontSee('data-reissue-order', false);

        $counter = $this->get(route('sales.direct.create', ['reissue' => $wrong->id]))->assertOk();
        $counter->assertViewHas('sourceResume', fn ($r) => is_array($r)
            && $r['invoiceId'] === ''
            && $r['invoiceNo'] === ''
            && (int) $r['customerId'] === (int) $this->data['customer_id']
            && $r['stage'] === 'source'
            && ($r['screen']['deposits'] ?? null) === []
            && collect($r['screen']['lines'] ?? [])->contains(fn ($l) => (int) ($l['id'] ?? 0) === (int) $this->biscuit->id));
        $counter->assertSee($paper->document_no);

        // ⓘ সংরক্ষণ মানে নতুন বিল — নতুন নম্বর; পুরনোটা বাতিলই
        $right = $this->sell('2');
        $this->assertNotSame($wrong->document_no, $right->document_no);
        $this->assertSame(DocumentStatus::CANCELLED, $wrong->fresh()->status);
    }

    public function test_a_bill_that_is_not_cancelled_opens_nothing(): void
    {
        $live = $this->sell('1');

        $this->get(route('sales.direct.create', ['reissue' => $live->id]))->assertOk()
            // ⚠️ `assertViewHas(চাবি, null)` কেবল চাবি দেখে, মান নয় — তাই closure
            ->assertViewHas('sourceResume', fn ($r) => $r === null);
    }

    public function test_without_the_bill_key_there_is_no_button_and_no_lines(): void
    {
        $wrong = $this->sell('1');
        $this->post(route('sales.invoice.cancellation', $wrong), ['reason' => 'ভুল'])->assertSessionHas('saved');
        $paper = SalesInvoiceCancellation::query()->where('sales_invoice_id', $wrong->id)->firstOrFail();

        $viewer = User::factory()->create(['is_active' => true, 'current_company_id' => CompanyContext::id()]);
        $viewer->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        $viewer->givePermissionTo(['sales.invoice.view', 'sales.invoice.cancellation']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($viewer);

        $this->get(route('sales.cancellation.show', $paper))->assertOk()->assertDontSee('data-reissue', false);
    }

    private function sell(string $qty): SalesInvoice
    {
        $this->actingAs($this->owner);

        return app(DirectSaleService::class)->complete($this->data, [['product_id' => $this->biscuit->id, 'qty' => $qty, 'rate' => '10', 'free_qty' => '0']])['invoice']->fresh();
    }
}
