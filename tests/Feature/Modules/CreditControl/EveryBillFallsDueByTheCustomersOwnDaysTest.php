<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditChange;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\DispatchBill;
use App\Modules\Sales\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ প্রতিটা বিলের মেয়াদ গ্রাহকের নিজের বাকির দিনে — বাকি নিয়ন্ত্রণ ধাপ ২.২ (মালিকের উত্তর ২, ১১ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে কেবল কাউন্টার মেয়াদ বসাত; অফিসের বিল, চালান থেকে বিল আর রওনার বিল (DO, ফোন, পোর্টাল — সবাই [[DispatchBill]] দিয়ে)
 * মেয়াদ খালি রাখত, আর দেয়াল বিলের দিনকেই মেয়াদ ধরত — ৩০ দিনের গ্রাহক পরের দিনই "মেয়াদ পেরোনো"।
 *   · প্রতিটা পথে: মেয়াদ = বিলের দিন + গ্রাহকের বাকির দিন; লেখা মেয়াদ থাকলে সেটাই।
 *   · বাকির পাতা থেকে বাকির দিন বদল — কারণ বাধ্যতামূলক, ইতিহাসে ওঠে; চাবি ছাড়া দরজা ৪০৩।
 */
final class EveryBillFallsDueByTheCustomersOwnDaysTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();

        $this->dealer = $this->customer('DAYS-30', '1000000');
        $this->dealer->forceFill(['credit_days' => 30])->save();
    }

    public function test_an_office_bill_falls_due_by_the_customers_days(): void
    {
        $bill = $this->draftBill($this->dealer, $this->stockedProduct(), '2');

        $this->assertSame(now()->addDays(30)->toDateString(), $bill->due_on?->toDateString(), '⛔ অফিসের বিল মেয়াদ খালি রেখেছে।');
    }

    public function test_a_written_due_date_still_wins(): void
    {
        $bill = app(SalesInvoiceService::class)->create(
            ['customer_id' => $this->dealer->id, 'warehouse_id' => $this->warehouseId(), 'trx_date' => now()->toDateString(),
                'due_on' => now()->addDays(7)->toDateString()],
            [['product_id' => $this->stockedProduct()->id, 'qty' => '1', 'rate' => '120']],
        );

        $this->assertSame(now()->addDays(7)->toDateString(), $bill->due_on?->toDateString());
    }

    public function test_a_back_dated_bill_counts_from_its_own_date(): void
    {
        $bill = app(SalesInvoiceService::class)->create(
            ['customer_id' => $this->dealer->id, 'warehouse_id' => $this->warehouseId(), 'trx_date' => now()->subDays(10)->toDateString()],
            [['product_id' => $this->stockedProduct()->id, 'qty' => '1', 'rate' => '120']],
        );

        $this->assertSame(now()->addDays(20)->toDateString(), $bill->due_on?->toDateString());
    }

    public function test_a_challan_billed_on_dispatch_falls_due_by_the_customers_days(): void
    {
        // ⓘ DO, ফোন আর পোর্টালের বিক্রি রওনায় এই পথেই বিল হয় ([[DeliveryStageService]] → [[DispatchBill]])
        $challan = app(DeliveryChallanService::class)->confirm(app(DeliveryChallanService::class)->create([
            'customer_id' => $this->dealer->id, 'warehouse_id' => $this->warehouseId(), 'trx_date' => now()->toDateString(), 'own_transport' => true,
        ], [['product_id' => $this->stockedProduct()->id, 'delivered_qty' => '2', 'rate' => '120']]));

        $bill = app(DispatchBill::class)->forDispatch($challan);

        $this->assertNotNull($bill);
        $this->assertSame(now()->addDays(30)->toDateString(), $bill->fresh()->due_on?->toDateString(), '⛔ রওনার বিল মেয়াদ খালি রেখেছে।');
    }

    public function test_a_counter_sale_falls_due_by_the_customers_days(): void
    {
        $sale = app(DirectSaleService::class)->complete(
            ['customer_id' => $this->dealer->id, 'warehouse_id' => $this->warehouseId(), 'trx_date' => now()->toDateString(), 'deposit' => '0'],
            [['product_id' => $this->stockedProduct()->id, 'qty' => '1', 'rate' => '120']],
        );

        $this->assertSame(now()->addDays(30)->toDateString(), $sale['invoice']->fresh()->due_on?->toDateString());
    }

    public function test_editing_a_draft_without_a_date_reads_the_days_again(): void
    {
        $product = $this->stockedProduct();
        $bill = $this->draftBill($this->dealer, $product, '1');

        $bill = app(SalesInvoiceService::class)->update($bill, ['trx_date' => now()->subDays(2)->toDateString()],
            [['product_id' => $product->id, 'qty' => '1', 'rate' => '120']]);

        $this->assertSame(now()->addDays(28)->toDateString(), SalesInvoice::query()->find($bill->id)->due_on?->toDateString());
    }

    public function test_the_profile_changes_credit_days_with_a_reason_into_the_history(): void
    {
        $this->post(route('credit_control.customer.credit_days', $this->dealer->id), ['credit_days' => 45, 'reason' => 'পুরনো ক্রেতা'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(45, $this->dealer->fresh()->credit_days);
        $row = CustomerCreditChange::query()->where('customer_id', $this->dealer->id)->where('field', 'credit_days')->latest('id')->firstOrFail();
        $this->assertSame(['30', '45', 'পুরনো ক্রেতা', $this->owner->id], [$row->old_value, $row->new_value, $row->reason, (int) $row->changed_by]);

        // ⓘ কারণ ছাড়া নয়
        $this->post(route('credit_control.customer.credit_days', $this->dealer->id), ['credit_days' => 60])->assertSessionHasErrors('reason');
        $this->assertSame(45, $this->dealer->fresh()->credit_days);
    }

    public function test_without_the_customer_key_the_door_refuses(): void
    {
        $viewer = $this->staffWith(['credit_control.view']);

        $this->actingAs($viewer)
            ->post(route('credit_control.customer.credit_days', $this->dealer->id), ['credit_days' => 90, 'reason' => 'x'])
            ->assertForbidden();

        $this->assertSame(30, $this->dealer->fresh()->credit_days);
    }

    private function warehouseId(): int
    {
        return (int) Warehouse::query()->where('is_default', true)->value('id');
    }
}
