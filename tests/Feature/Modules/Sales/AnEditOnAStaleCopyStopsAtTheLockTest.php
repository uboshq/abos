<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesQuotationService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * এক ট্যাবে পাকা, আরেক ট্যাবে সম্পাদনা — সম্পাদনা তালায় থামে (Sales লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬)।
 *
 * ⓘ দৃশ্যটা: খসড়া কাগজ খোলা দুই ট্যাবে; প্রথমটা পাকা করল (এখানে সরাসরি সারিতে অবস্থা বদলে — অন্য অনুরোধটা যা রেখে যায়),
 * দ্বিতীয়টা হাতের পুরনো কপি নিয়ে সম্পাদনা পাঠাল। ⛔ আগে "খসড়া কি না" দেখা হত সেই পুরনো কপি থেকে, তাই সম্পাদনা পাকা
 * কাগজের সারি আর অঙ্ক বদলে দিত। এখন তালার ভিতরে তাজা অবস্থা — সম্পাদনা ফেরে, সারি অক্ষত।
 */
final class AnEditOnAStaleCopyStopsAtTheLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_a_challan(): void
    {
        $service = app(DeliveryChallanService::class);
        $stale = $service->create($this->paper() + ['own_transport' => true, 'vehicle_no' => 'DHA-GA-11-2233'],
            [['product_id' => $this->product()->id, 'delivered_qty' => '1', 'rate' => '10']]);

        $this->assertStopped($stale, DocumentStatus::CONFIRMED, fn () => $service->update($stale, $this->paper(),
            [['product_id' => $this->product()->id, 'delivered_qty' => '50', 'rate' => '10']]));
    }

    public function test_an_order(): void
    {
        $service = app(SalesOrderService::class);
        $stale = $service->create($this->paper(), [['product_id' => $this->product()->id, 'ordered_qty' => '2', 'rate' => '10']]);

        $this->assertStopped($stale, DocumentStatus::CONFIRMED, fn () => $service->update($stale, $this->paper(),
            [['product_id' => $this->product()->id, 'ordered_qty' => '50', 'rate' => '10']]));
    }

    public function test_a_bill(): void
    {
        $service = app(SalesInvoiceService::class);
        $stale = $service->create($this->paper(), [['product_id' => $this->product()->id, 'qty' => '1', 'rate' => '100']]);

        $this->assertStopped($stale, DocumentStatus::CONFIRMED, fn () => $service->update($stale, $this->paper(),
            [['product_id' => $this->product()->id, 'qty' => '50', 'rate' => '100']]));
    }

    public function test_a_return(): void
    {
        $service = app(SalesReturnService::class);
        $paper = $this->paper() + ['reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id')];
        $stale = $service->create($paper, [['product_id' => $this->product()->id, 'qty' => '1', 'rate' => '10']]);

        $this->assertStopped($stale, DocumentStatus::CONFIRMED, fn () => $service->update($stale, $paper,
            [['product_id' => $this->product()->id, 'qty' => '50', 'rate' => '10']]));
    }

    /** ⛔ ফেরতের বিল বদলালে বিক্রির নম্বরও বদলায় — আগে পুরনো বিলের নম্বর থেকে যেত (১৩ অক্টোবর ২০২৬) */
    public function test_a_return_moved_to_another_bill_takes_that_bills_sale_number(): void
    {
        $bills = app(\App\Modules\Sales\Services\SalesInvoiceService::class);
        $line = [['product_id' => $this->product()->id, 'qty' => '1', 'rate' => '10']];
        $first = $bills->create($this->paper(), $line);
        $second = $bills->create($this->paper(), $line);
        DB::table('sal_invoices')->where('id', $first->id)->update(['sale_no' => 'S-FIRST']);
        DB::table('sal_invoices')->where('id', $second->id)->update(['sale_no' => 'S-SECOND']);

        $service = app(SalesReturnService::class);
        $paper = $this->paper() + ['reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id')];
        $return = $service->create($paper + ['sales_invoice_id' => $first->id], $line);
        $this->assertSame('S-FIRST', $return->fresh()->sale_no, 'দৃশ্যটাই ভুল — ফেরতে প্রথম বিলের নম্বর বসেনি।');

        $service->update($return->fresh(), $paper + ['sales_invoice_id' => $second->id], $line);

        $this->assertSame('S-SECOND', $return->fresh()->sale_no, '⛔ ফেরত দ্বিতীয় বিলে গেল, অথচ বিক্রির নম্বর পুরনো বিলের।');
    }

    public function test_a_quotation_edit_submit_and_cancel(): void
    {
        $service = app(SalesQuotationService::class);
        $paper = ['customer_id' => $this->customer()->id, 'trx_date' => now()->toDateString(), 'valid_until' => now()->addDays(7)->toDateString()];
        $stale = $service->create($paper, [['product_id' => $this->product()->id, 'qty' => '1', 'rate' => '10']]);

        $this->assertStopped($stale, SalesQuotation::SUBMITTED, fn () => $service->update($stale, $paper,
            [['product_id' => $this->product()->id, 'qty' => '50', 'rate' => '10']]));

        // ⓘ দুইবার "জমা" — দ্বিতীয়টা তাজা অবস্থায় জমা দেখে থামে
        $this->assertStopped($stale, SalesQuotation::SUBMITTED, fn () => $service->submit($stale));

        // ⓘ রূপান্তর আর বাতিল একসাথে — রূপান্তরিত উদ্ধৃতি "বাতিল" হয় না
        $this->assertStopped($stale, SalesQuotation::CONVERTED, fn () => $service->cancel($stale, 'ভুল'));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** অন্য অনুরোধ সারিটা বদলে গেছে; হাতের কপি এখনো পুরনো — কাজটা থামবে আর সারির অবস্থা আর লাইন অক্ষত থাকবে */
    private function assertStopped(Model $stale, string $status, callable $act): void
    {
        DB::table($stale->getTable())->where('id', $stale->getKey())->update(['status' => $status]);
        $lines = DB::table($stale->lines()->getRelated()->getTable())
            ->where($stale->lines()->getForeignKeyName(), $stale->getKey())->orderBy('id')->get()->toJson();

        try {
            $act();
            $this->fail('⛔ পুরনো কপির কাজ তালার পরেও চলে গেল: '.class_basename($stale).' ('.$status.')');
        } catch (ValidationException) {
            // ⓘ ঠিক এটাই চাই
        }

        $this->assertSame($status, DB::table($stale->getTable())->where('id', $stale->getKey())->value('status'), '⛔ অবস্থা বদলে গেল');
        $this->assertSame($lines, DB::table($stale->lines()->getRelated()->getTable())
            ->where($stale->lines()->getForeignKeyName(), $stale->getKey())->orderBy('id')->get()->toJson(), '⛔ পাকা কাগজের সারি বদলে গেল');
    }

    /** @return array<string, mixed> */
    private function paper(): array
    {
        return ['customer_id' => $this->customer()->id, 'warehouse_id' => $this->warehouse()->id, 'trx_date' => now()->toDateString()];
    }

    private function customer(): Customer
    {
        return Customer::query()->orderBy('id')->firstOrFail();
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    private function product(): Product
    {
        return Product::query()->orderBy('id')->firstOrFail();
    }
}
