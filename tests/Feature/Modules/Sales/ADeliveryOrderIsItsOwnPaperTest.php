<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Events\DeliveryOrderCancelled;
use App\Modules\Sales\Events\DeliveryOrderSupervisorApproved;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ডেলিভারি অর্ডার — নিজের কাগজ, নিজের ক্রম (মালিকের বিক্রয়-ধারা, ২ অক্টোবর ২০২৬; টুকরো ১)।
 *
 * ⭐ দাবি:
 *   DO-র নিজের নম্বর-ক্রম (DO-…), চালান বা বিক্রয় আদেশের ক্রম থেকে আলাদা;
 *   চূড়ান্ত পরিমাণ = সুপারভাইজারের বদল, নাহলে যা চাওয়া — আর মোট সেটা ধরেই আবার গোনা (abos-86 এটাই পড়েন);
 *   কোম্পানির দেয়াল — অন্য কোম্পানির DO দেখা যায় না;
 *   দুই সংকেতে তিন সেশনের চুক্তির ঘরগুলো আছে।
 */
final class ADeliveryOrderIsItsOwnPaperTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_do_has_its_own_number_series(): void
    {
        $engine = app(NumberSeriesEngine::class);
        $do = $engine->next('DO');
        $so = $engine->next('SO');

        $this->assertStringStartsWith('DO', $do, 'DO-র নিজের উপসর্গ নেই।');
        $this->assertNotSame($do, $so);
    }

    public function test_the_final_quantity_is_the_supervisors_and_the_total_follows_it(): void
    {
        $order = $this->order();
        $line = $order->lines->first();

        $this->assertSame(0, bccomp('10', $line->finalQty(), 4), 'বদল না হলে চাওয়া পরিমাণই চূড়ান্ত।');
        $this->assertSame(0, bccomp('950', (string) $order->fresh()->total, 4), '10 × 100 − 5% = 950');

        $line->forceFill(['approved_qty' => '6'])->save();
        $order->recalculate();

        $this->assertSame(0, bccomp('6', $line->fresh()->finalQty(), 4));
        $this->assertSame(0, bccomp('570', (string) $order->fresh()->total, 4), '⛔ সুপারভাইজার কমালেন, অথচ মোট পুরনো রইল।');
    }

    public function test_another_company_s_do_is_not_seen(): void
    {
        $order = $this->order();
        $other = Company::query()->whereKeyNot($this->company->id)->firstOrFail();

        CompanyContext::set($other->id, null);
        $this->assertNull(DeliveryOrder::query()->find($order->id), '⛔ অন্য কোম্পানির DO দেখা গেল।');
    }

    public function test_the_two_signals_carry_what_the_other_sessions_read(): void
    {
        $order = $this->order();

        $approved = DeliveryOrderSupervisorApproved::from($order);
        foreach (['delivery_order_id', 'public_id', 'customer_id', 'total'] as $key) {
            $this->assertArrayHasKey($key, $approved->payload);
        }
        $this->assertSame((int) $this->company->id, $approved->companyId);

        $this->assertSame('rejected', DeliveryOrderCancelled::from($order, 'rejected')->payload['reason']);
        $this->assertSame(DeliveryOrderStatus::DRAFT, $order->status);
        $this->assertTrue($order->isEditableByWriter());
    }

    /** ⭐ abos-bb-র কাউন্টার-উৎস চুক্তি — কেবল হিসাবে অনুমোদিত থেকে, একবারই, একটা বিলেই */
    public function test_the_counter_contract_opens_only_when_accounts_approved_and_invoices_once(): void
    {
        $order = $this->order();

        $this->assertSame('do', DeliveryOrder::counterSourceKey());
        try {
            $order->assertReadyForCounter();
            $this->fail('⛔ খসড়া DO কাউন্টারে খুলে গেল।');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('source', $e->errors());
        }

        $order->forceFill(['status' => DeliveryOrderStatus::ACCOUNTS_APPROVED])->save();
        $order->lines->first()->forceFill(['approved_qty' => '6'])->save();
        $order->assertReadyForCounter();

        $screen = $order->counterScreen();
        $this->assertSame(0, bccomp('6', $screen['lines'][0]['qty'], 4), 'কাউন্টারে চূড়ান্ত পরিমাণ যায়নি।');
        $this->assertSame((int) $order->lines->first()->id, $screen['lines'][0]['source_line_id']);

        // ⓘ কাউন্টারের রাখা খসড়া বাদ গেলে DO ডিপোর তালিকায় ফেরে (abos-bb-র leaveDepotCheck)
        $order->leaveDepotCheck();   // যাচাইয়ে নেই — কিছু হয় না
        $this->assertSame(DeliveryOrderStatus::ACCOUNTS_APPROVED, $order->fresh()->status);
        $order->enterDepotCheck();
        $order->leaveDepotCheck();
        $this->assertSame(DeliveryOrderStatus::ACCOUNTS_APPROVED, $order->fresh()->status, '⛔ বাদ দেওয়া খসড়ার DO ডিপো-যাচাইয়েই আটকে রইল।');

        $order->enterDepotCheck();
        $order->enterDepotCheck();   // দুইবার — কিছু হয় না
        $this->assertSame(DeliveryOrderStatus::DEPOT_CHECK, $order->fresh()->status);

        $invoice = $this->invoice('ZQ-INV-1');
        $order->markInvoiced($invoice);
        $order->markInvoiced($invoice);   // একই বিলে আবার — কিছু হয় না
        $this->assertSame(DeliveryOrderStatus::INVOICED, $order->fresh()->status);
        $this->assertSame((int) $invoice->id, (int) $order->fresh()->sales_invoice_id);

        $other = $this->invoice('ZQ-INV-2');
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $order->markInvoiced($other);
    }

    /** ডেমোতে বিল নেই — চুক্তির জন্য কেবল একটা সারি লাগে (খাতায় কিছু ওঠে না) */
    private function invoice(string $no): \App\Modules\Sales\Models\SalesInvoice
    {
        return \App\Modules\Sales\Models\SalesInvoice::query()->forceCreate([
            'company_id' => $this->company->id, 'document_no' => $no,
            'customer_id' => Customer::query()->firstOrFail()->id, 'trx_date' => now()->toDateString(),
        ]);
    }

    private function order(): DeliveryOrder
    {
        $order = DeliveryOrder::query()->create([
            'document_no' => app(NumberSeriesEngine::class)->next('DO'),
            'customer_id' => Customer::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'status' => DeliveryOrderStatus::DRAFT,
            'created_by' => auth()->id(),
        ]);
        $order->lines()->create([
            'product_id' => Product::query()->firstOrFail()->id,
            'qty' => '10', 'rate' => '100', 'discount_percent' => '5',
        ]);
        $order->recalculate();

        return $order->fresh('lines');
    }
}
