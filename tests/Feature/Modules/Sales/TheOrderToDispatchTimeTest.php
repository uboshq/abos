<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Models\DeliveryEvent;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Reports\DeliveryReports;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ আদেশ থেকে রওনার সময় — বিক্রয় পরিকল্পনা সংস্করণ ২ §৯ (ঘ) ([[DeliveryReports::ORDER_TO_DISPATCH]])।
 *
 * ⛔ প্রতিটা ধাপের প্রথম সময়: পাঠানো (না থাকলে তৈরি) → অনুমোদন → বিল → প্রথম দেওয়া গেট পাস (বাতিল নয়) → প্রথম রওনা (বিভক্ত
 * চালানের পরেরটা নয়); খসড়া আর বাতিল DO নয়; রওনা না হলে মোট ফাঁকা আর গড়ে নয়; গড় = রওনা হওয়াগুলোর মোট ÷ সংখ্যা।
 */
final class TheOrderToDispatchTimeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->t0 = now()->subDays(3)->startOfHour();
    }

    public function test_every_leg_is_timed_from_its_first_moment(): void
    {
        // ⓘ A — পাঠানো t0, অনুমোদন +৩, বিল +৫, গেট পাস +৮ (+৬-এর বাতিলটা নয়), রওনা +১০ (দ্বিতীয় চালান +৩০)
        // ⓘ খসড়া ৫ ঘণ্টা আগে লেখা — ঘড়ি চলে পাঠানো থেকে, লেখা থেকে নয়
        $a = $this->order('DO-A', DeliveryOrderStatus::INVOICED, submitted: 0, approved: 3, created: -5);
        [$a1, $a2] = $this->bill($a, 5, ['CH-A1', 'CH-A2']);
        $this->gate($a1, 6, GatePass::CANCELLED);
        $this->gate($a1, 8, GatePass::ISSUED);
        $this->leave($a1, 10);
        $this->gate($a2, 28, GatePass::ISSUED);
        $this->leave($a2, 30);

        // ⓘ B — বিল হয়েছে, রওনা হয়নি; C — পাঠানোর সময় নেই, তৈরি t0, রওনা +২০
        $b = $this->order('DO-B', DeliveryOrderStatus::INVOICED, submitted: 0, approved: 1);
        $this->bill($b, 2, ['CH-B1']);
        $c = $this->order('DO-C', DeliveryOrderStatus::INVOICED, submitted: null, approved: 4);
        [$c1] = $this->bill($c, 6, ['CH-C1']);
        $this->gate($c1, 18, GatePass::ISSUED);
        $this->leave($c1, 20);

        $this->order('DO-DRAFT', DeliveryOrderStatus::DRAFT, submitted: 0, approved: null);
        $this->order('DO-GONE', DeliveryOrderStatus::CANCELLED, submitted: 0, approved: null);

        $result = app(ReportEngine::class)->run(DeliveryReports::ORDER_TO_DISPATCH, ['from' => now()->subDays(5)->toDateString(), 'to' => now()->toDateString()]);
        $rows = collect($result->rows)->map(fn ($r) => (array) $r)->keyBy('do_no');

        $this->assertSame(['DO-A', 'DO-B', 'DO-C'], $rows->keys()->sort()->values()->all(), '⛔ খসড়া বা বাতিল DO গোনা হল');
        $this->assertSame([3, 2, 3, 2, 10], array_map('intval', [
            $rows['DO-A']['hours_to_approve'], $rows['DO-A']['hours_to_invoice'], $rows['DO-A']['hours_to_gate'],
            $rows['DO-A']['hours_to_leave'], $rows['DO-A']['hours_total'],
        ]), '⛔ কোনো ধাপের প্রথম সময় ধরা হয়নি (বাতিল গেট পাস, বা বিভক্ত চালানের পরেরটা)');
        $this->assertNull($rows['DO-B']['hours_total'], '⛔ রওনা না হওয়া DO-র মোট ঘণ্টা');
        $this->assertSame(0, (int) $rows['DO-B']['dispatched']);
        $this->assertSame(20, (int) $rows['DO-C']['hours_total'], '⛔ পাঠানোর সময় না থাকলে তৈরির সময় থেকে নয়');

        $definition = app(ReportEngine::class)->get(DeliveryReports::ORDER_TO_DISPATCH);
        $said = ($definition->summary)($result->totals);
        $this->assertSame('15.0', $said['value'], '⛔ গড় রওনা হওয়া দুটোর (১০ + ২০) ÷ ২ নয়');

        $this->get(route('sales.report.show', ['slug' => 'order-to-dispatch', 'from' => now()->subDays(5)->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()->assertSee('DO-A');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function at(?int $hours): ?Carbon
    {
        return $hours === null ? null : $this->t0->copy()->addHours($hours);
    }

    private function order(string $no, string $status, ?int $submitted, ?int $approved, int $created = 0): DeliveryOrder
    {
        return DeliveryOrder::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => $no, 'customer_id' => Customer::query()->value('id'),
            'trx_date' => $this->t0->toDateString(), 'status' => $status,
            'submitted_at' => $this->at($submitted), 'approved_at' => $this->at($approved),
            'created_at' => $this->at($created), 'updated_at' => $this->t0,
        ]);
    }

    /** @param  list<string>  $challans  @return list<DeliveryChallan> বিল আর তার চালান (বিভক্ত হলে একাধিক), সব একসাথে তৈরি */
    private function bill(DeliveryOrder $order, int $hours, array $challans): array
    {
        $at = $this->at($hours);
        $invoice = SalesInvoice::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $order->branch_id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->firstOrFail()->id,
            'document_no' => 'INV-'.$order->document_no, 'customer_id' => $order->customer_id,
            'trx_date' => $at->toDateString(), 'subtotal' => '100', 'total' => '100', 'status' => DocumentStatus::CONFIRMED,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $order->forceFill(['sales_invoice_id' => $invoice->id])->save();

        $made = [];

        foreach ($challans as $n => $no) {
            $challan = DeliveryChallan::query()->forceCreate([
                'company_id' => $this->company->id, 'branch_id' => $order->branch_id, 'document_no' => $no,
                'customer_id' => $order->customer_id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => $at->toDateString(), 'total' => '0', 'status' => DocumentStatus::CONFIRMED,
                'created_at' => $at, 'updated_at' => $at,
            ]);
            $line = DeliveryChallanLine::query()->forceCreate([
                'delivery_challan_id' => $challan->id, 'product_id' => Product::query()->value('id'),
                'delivered_qty' => '1', 'rate' => '100', 'amount' => '100', 'line_no' => 1,
            ]);
            (new SalesInvoiceLine)->forceFill([
                'line_no' => $n + 1, 'sales_invoice_id' => $invoice->id, 'product_id' => Product::query()->value('id'),
                'delivery_challan_line_id' => $line->id, 'qty' => '1', 'rate' => '100', 'amount' => '100', 'tax' => '0',
            ])->save();
            $made[] = $challan;
        }

        return $made;
    }

    private function gate(DeliveryChallan $challan, int $hours, string $status): void
    {
        $event = DeliveryEvent::query()->forceCreate([
            'company_id' => $this->company->id, 'delivery_challan_id' => $challan->id, 'to_stage' => DeliveryStage::PACKED,
            'source' => DeliveryStage::BY_HAND, 'occurred_at' => $this->at($hours),
        ]);

        GatePass::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $challan->branch_id, 'document_no' => 'GP-'.$challan->document_no.'-'.$hours,
            'delivery_challan_id' => $challan->id, 'delivery_event_id' => $event->id, 'issued_at' => $this->at($hours), 'status' => $status,
        ]);
    }

    private function leave(DeliveryChallan $challan, int $hours): void
    {
        DeliveryEvent::query()->forceCreate([
            'company_id' => $this->company->id, 'delivery_challan_id' => $challan->id, 'to_stage' => DeliveryStage::DISPATCHED,
            'source' => DeliveryStage::BY_HAND, 'occurred_at' => $this->at($hours),
        ]);
    }
}
