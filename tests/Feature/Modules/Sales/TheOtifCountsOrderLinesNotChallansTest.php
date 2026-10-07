<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryEvent;
use App\Modules\Sales\Models\DeliveryEventLine;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Reports\DeliveryReports;
use App\Modules\Sales\Services\DeliveryPerformance;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Support\SalesOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ OTIF আদেশের লাইন ধরে — বিক্রয় পরিকল্পনা সংস্করণ ২ §৯ (৬ অক্টোবর ২০২৬, সমন্বয়কের সিদ্ধান্ত; [[DeliveryReports::OTIF]])।
 *
 * প্রতিটা আদেশে এক লাইন, ১০টা:
 *   A আজকের জন্য, আজ পুরো পৌঁছেছে                                   → সময়মতো ও পুরো
 *   B আজকের জন্য, আজ আংশিক — ৬টা নিল                                → কম
 *   C গতকালের জন্য, আজ পুরো                                          → পুরো, দেরিতে
 *   D পরশুর জন্য, পথে                                                 → পৌঁছায়নি
 *   E আগামীকালের জন্য                                                 → সামনে — গোনায় নেই
 *   F আজকের জন্য, ৪টা ফেরানো (চূড়ান্ত ৬), ৬টা পৌঁছেছে                → সময়মতো ও পুরো
 *   G আজকের জন্য, দুই চালানে ৫+৫ — একটা পৌঁছেছে, আরেকটা পথে        → কম ⛔ আগে "পুরো" গুনত (চালান ধরে)
 *   H বাতিল আদেশ · I ফেরানো লাইন                                     → কোথাও নেই
 *   J খসড়া চালানে "পৌঁছেছে" ঘটনা → পৌঁছায়নি · K অন্য শাখায়, পুরো → সময়মতো ও পুরো (শাখা বাছলে বাদ)
 * ⇒ গোনায় ৮ (A B C D F G J K), সময়মতো ও পুরো ৩ (A F K) = ৩৭.৫%; সময়মতো কিছু ৫ (A B F G K); পুরো ৪ (A C F K)।
 */
final class TheOtifCountsOrderLinesNotChallansTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private int $customer;

    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->customer = (int) Customer::query()->value('id');
        $this->product = (int) Product::query()->value('id');

        // ⓘ ডেমোর নিজের আদেশ এ মাসের বাইরে — গোনা যেন কেবল এগুলোর
        SalesOrder::query()->update(['deliver_on' => now()->subYear()->toDateString(), 'trx_date' => now()->subYear()->toDateString()]);

        $today = now()->toDateString();
        [$a] = $this->order('A', $today);
        $this->ship($a, '10', DeliveryStage::DELIVERED);

        [$b] = $this->order('B', $today);
        $this->ship($b, '10', DeliveryStage::PARTIALLY_DELIVERED, took: '6');

        [$c] = $this->order('C', now()->subDay()->toDateString());
        $this->ship($c, '10', DeliveryStage::DELIVERED);

        [$d] = $this->order('D', now()->subDays(2)->toDateString());
        $this->ship($d, '10', DeliveryStage::DISPATCHED);

        $this->order('E', now()->addDay()->toDateString());

        [$f] = $this->order('F', $today, rejected: '4');
        $this->ship($f, '6', DeliveryStage::DELIVERED);

        [$g] = $this->order('G', $today);
        $this->ship($g, '5', DeliveryStage::DELIVERED);
        $this->ship($g, '5', DeliveryStage::DISPATCHED);

        [$h] = $this->order('H', $today, status: SalesOrderStatus::CANCELLED);
        $this->ship($h, '10', DeliveryStage::DELIVERED);

        [$i] = $this->order('I', $today, lineStatus: SalesOrderStatus::LINE_REJECTED);
        $this->ship($i, '10', DeliveryStage::DELIVERED);

        // ⛔ J — খসড়া চালানে "পৌঁছেছে" ঘটনা: টাকা-মালের কাগজ নয়, তাই পৌঁছায়নি
        [$j] = $this->order('J', $today);
        $this->ship($j, '10', DeliveryStage::DELIVERED, challanStatus: DocumentStatus::DRAFT);

        // ⓘ K — অন্য শাখার আদেশ, পুরো পৌঁছেছে
        $other = \App\Models\Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('id', '!=', $this->company->defaultBranch()?->id)->orderBy('id')->firstOrFail();
        [$k] = $this->order('K', $today, branch: (int) $other->id);
        $this->ship($k, '10', DeliveryStage::DELIVERED);
    }

    public function test_each_order_line_is_measured_against_its_own_quantity_and_promise(): void
    {
        $rows = collect($this->report()->rows)->map(fn ($r) => (array) $r)->keyBy('document_no');

        $this->assertSame(['SO-A', 'SO-B', 'SO-C', 'SO-D', 'SO-E', 'SO-F', 'SO-G', 'SO-J', 'SO-K'], $rows->keys()->sort()->values()->all(),
            '⛔ বাতিল আদেশ বা ফেরানো লাইন গোনা হল');

        $state = fn (string $no) => $rows[$no]['state'];
        $this->assertSame(__('sales::otif.state_otif'), $state('SO-A'));
        $this->assertSame(__('sales::otif.state_short'), $state('SO-B'), 'আংশিক — নেওয়া ৬, চাওয়া ১০');
        $this->assertSame(__('sales::otif.state_late'), $state('SO-C'));
        $this->assertSame(__('sales::otif.state_none'), $state('SO-D'));
        $this->assertSame(__('sales::otif.state_upcoming'), $state('SO-E'));
        $this->assertSame(__('sales::otif.state_otif'), $state('SO-F'), '⛔ ফেরানো অংশও চাওয়া ধরা হল');
        $this->assertSame(__('sales::otif.state_short'), $state('SO-G'), '⛔ আদেশের অর্ধেক পৌঁছানো চালানকে "পুরো" ধরা হল');
        $this->assertSame(__('sales::otif.state_none'), $state('SO-J'), '⛔ খসড়া চালানের ঘটনা পৌঁছানো ধরা হল');

        $this->assertSame(0, bccomp('6', (string) $rows['SO-B']['on_time_qty'], 4), 'আংশিক ঘটনার লাইনে যতটা গেল');
        $this->assertSame(0, bccomp('6', (string) $rows['SO-F']['wanted'], 4), 'চূড়ান্ত = ১০ − ৪');
        $this->assertSame(0, (int) $rows['SO-E']['due']);

        $summary = ($this->report()->report->summary)($this->report()->totals);
        $this->assertSame('37.5', $summary['value'], '৩ ÷ ৮ — A F K সময়মতো ও পুরো, J আর K গোনায়');

        // ⓘ একটা শাখা বাছলে কেবল সেই শাখার আদেশ
        $mine = collect(app(ReportEngine::class)->run(DeliveryReports::OTIF, [
            'from' => now()->subDays(3)->toDateString(), 'to' => now()->addDays(3)->toDateString(), 'branch_id' => $this->company->defaultBranch()?->id,
        ])->rows)->map(fn ($r) => (array) $r)->pluck('document_no');
        $this->assertFalse($mine->contains('SO-K'), '⛔ অন্য শাখার আদেশ দেখাল');
        $this->assertTrue($mine->contains('SO-A'));
        $this->assertFalse($summary['good']);
    }

    /** ⭐ ড্যাশবোর্ড আর রিপোর্ট একই কথা বলে */
    public function test_the_dashboard_counts_the_same_lines(): void
    {
        $summary = app(DeliveryPerformance::class)->summary(now()->subDays(3)->toDateString(), now()->addDays(3)->toDateString());

        $this->assertSame(8, $summary['due']);
        $this->assertSame(3, $summary['otif']);
        $this->assertSame(5, $summary['on_time'], 'সময়মতো কিছু পৌঁছেছে — A B F G K');
        $this->assertSame(4, $summary['in_full'], 'মোট পুরো পৌঁছেছে — A C F K');
        $this->assertSame('37.5', $summary['percent']);
    }

    public function test_the_page_opens(): void
    {
        $this->get(route('sales.report.show', ['slug' => 'otif', 'from' => now()->subDays(3)->toDateString(), 'to' => now()->addDays(3)->toDateString()]))
            ->assertOk()->assertSee('SO-G')->assertSee('37.5');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function report(): \App\Core\Engines\Report\ReportResult
    {
        return app(ReportEngine::class)->run(DeliveryReports::OTIF, [
            'from' => now()->subDays(3)->toDateString(), 'to' => now()->addDays(3)->toDateString(),
        ]);
    }

    /** @return array{0: int} আদেশের লাইনের id */
    private function order(string $no, string $deliverOn, string $rejected = '0', string $status = DocumentStatus::CONFIRMED, ?string $lineStatus = null, ?int $branch = null): array
    {
        $order = SalesOrder::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $branch ?? $this->company->defaultBranch()?->id,
            'document_no' => 'SO-'.$no, 'customer_id' => $this->customer, 'trx_date' => now()->subDays(3)->toDateString(),
            'deliver_on' => $deliverOn, 'subtotal' => '0', 'total' => '0', 'status' => $status,
        ]);

        $line = DB::table('sal_order_lines')->insertGetId([
            'sales_order_id' => $order->id, 'product_id' => $this->product, 'ordered_qty' => '10', 'rejected_qty' => $rejected,
            'rate' => '100', 'amount' => '1000', 'line_no' => 1, 'line_status' => $lineStatus ?? SalesOrderStatus::LINE_OPEN,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$line];
    }

    /** একটা চালান, এক লাইন — ধাপে পৌঁছালে ঘটনা; আংশিক হলে ঘটনার লাইনে কতটা নিল */
    private function ship(int $orderLine, string $qty, string $stage, ?string $took = null, string $challanStatus = DocumentStatus::CONFIRMED): void
    {
        $orderId = (int) DB::table('sal_order_lines')->where('id', $orderLine)->value('sales_order_id');

        $challan = DeliveryChallan::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'CH-'.$orderLine.'-'.random_int(100, 999), 'customer_id' => $this->customer, 'sales_order_id' => $orderId,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(), 'total' => '0', 'status' => $challanStatus,
        ]);

        $line = DB::table('sal_challan_lines')->insertGetId([
            'delivery_challan_id' => $challan->id, 'product_id' => $this->product, 'sales_order_line_id' => $orderLine,
            'delivered_qty' => $qty, 'rate' => '100', 'amount' => bcmul($qty, '100', 4), 'line_no' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DeliveryState::query()->updateOrCreate(['delivery_challan_id' => $challan->id],
            ['company_id' => $this->company->id, 'stage' => $stage, 'stage_at' => now()]);

        if (in_array($stage, [DeliveryStage::DELIVERED, DeliveryStage::PARTIALLY_DELIVERED], true)) {
            $event = DeliveryEvent::query()->forceCreate([
                'company_id' => $this->company->id, 'delivery_challan_id' => $challan->id, 'to_stage' => $stage,
                'source' => DeliveryStage::BY_HAND, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($took !== null) {
                DeliveryEventLine::query()->forceCreate([
                    'company_id' => $this->company->id, 'delivery_event_id' => $event->id,
                    'delivery_challan_line_id' => $line, 'delivered_qty' => $took,
                ]);
            }
        }
    }
}
