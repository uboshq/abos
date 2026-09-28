<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * মাল বেরোল, বিল কেউ বানাল না — মালিকের সিদ্ধান্ত, ২৯ সেপ্টেম্বর ২০২৬: *"ডেলিভারি বের হলেই ইনভয়েজ"*।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * অফিসের DO-তে চালান নিশ্চিত হত, গাড়ি বেরোত, আর বিলটা কেউ পরে হাতে বানাত — বা ভুলে
 * যেত। মাল দোকানে, অথচ খাতায় প্রাপ্য নেই।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * মাল গেট পেরোলেই (রওনা, বা গুদাম থেকে সোজা "পৌঁছেছে") বিল হয়, গেট পাসের সাথে, একই
 * বিক্রির নম্বরে ([[DispatchBill]])। আগে থেকে বিল থাকলে দ্বিতীয়টা নয়। আর কাউন্টারের দুই পথ:
 * "এখনই হাতে হাতে" এক চাপে পৌঁছেছে ও গেট পাস; "পরে পাঠানো" ডেলিভারির তালিকায় অপেক্ষা করে।
 */
final class TheGoodsLeftAndNobodyBilledThemTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
    }

    /** ⭐ রওনা হলো — বিল হলো, পাকা, চালানের দামে আর বিক্রির নম্বরে; গেট পাসও। */
    public function test_dispatch_makes_the_bill_with_the_sales_number(): void
    {
        $challan = $this->confirmedChallan();
        $this->assertSame(0, $this->billsOf($challan)->count(), 'প্রস্তুতিটাই ভুল — রওনার আগেই বিল আছে।');

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);

        $bills = $this->billsOf($challan);
        $this->assertCount(1, $bills, '⛔ মাল বেরোল, অথচ বিল হলো না।');

        $bill = $bills->first();
        $this->assertSame(DocumentStatus::CONFIRMED, $bill->status, '⛔ বিলটা খসড়া রয়ে গেছে — খাতায় প্রাপ্য নেই।');
        $this->assertSame((string) $challan->sale_no, (string) $bill->document_no, '⛔ বিল বিক্রির নম্বর পায়নি।');
        $this->assertSame(0, bccomp((string) $challan->fresh()->total, (string) $bill->total, 2), '⛔ বিলের অঙ্ক চালানের সাথে মেলে না।');
        $this->assertSame(1, GatePass::query()->where('delivery_challan_id', $challan->id)->count());
    }

    /** ⛔ পৌঁছায়নি থেকে আবার রওনা — নতুন গেট পাস, কিন্তু দ্বিতীয় বিল নয়। */
    public function test_a_second_dispatch_never_bills_twice(): void
    {
        $challan = $this->confirmedChallan();
        $stages = app(DeliveryStageService::class);

        $stages->move($challan, DeliveryStage::DISPATCHED);
        $stages->move($challan, DeliveryStage::FAILED, ['note' => 'দোকান বন্ধ']);
        $stages->move($challan, DeliveryStage::DISPATCHED);

        $this->assertSame(1, $this->billsOf($challan)->count(), '⛔ একই মালের দুইটা বিল — প্রাপ্য দুইবার।');
        $this->assertSame(2, GatePass::query()->where('delivery_challan_id', $challan->id)->count());
    }

    /** ⛔ রওনার আগে কেউ হাতে বিল করে থাকলে রওনায় দ্বিতীয় বিল নয়। */
    public function test_a_bill_made_by_hand_before_dispatch_is_not_repeated(): void
    {
        $challan = $this->confirmedChallan();
        $line = $challan->fresh('lines')->lines->firstOrFail();

        $invoices = app(SalesInvoiceService::class);
        $invoices->confirm($invoices->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'delivery_challan_line_id' => $line->id,
            'qty' => (string) $line->delivered_qty,
            'rate' => (string) $line->rate,
        ]]));

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);

        $this->assertSame(1, $this->billsOf($challan)->count(), '⛔ হাতে বানানো বিলের পরে রওনায় আরেকটা বিল।');
    }

    /** ⭐ গুদাম থেকে সোজা "পৌঁছেছে" (ক্রেতার নিজের গাড়ি) — মাল গেট পেরোল, তাই বিল আর গেট পাস। */
    public function test_straight_to_delivered_also_bills_and_issues_a_gate_pass(): void
    {
        $challan = $this->confirmedChallan();

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DELIVERED, ['receiver_name' => 'রহিম']);

        $this->assertSame(1, $this->billsOf($challan)->count(), '⛔ সোজা পৌঁছানোর পথে বিল হলো না।');
        $this->assertSame(1, GatePass::query()->where('delivery_challan_id', $challan->id)->count(),
            '⛔ সোজা পৌঁছানোর পথে গেট পাস নেই — মাল কাগজ ছাড়া গেট পেরোল।');
    }

    /** ⭐ কাউন্টারে "এখনই হাতে হাতে" — এক চাপে পৌঁছেছে, গেট পাস, একটাই বিল; প্রাপক খালি হলে গ্রাহক। */
    public function test_the_counter_hands_over_now_in_one_press(): void
    {
        $this->sell(['hand_over' => 'now'])->assertSessionHasNoErrors();

        $bill = SalesInvoice::query()->latest('id')->firstOrFail();
        $challan = $this->challanOf($bill);

        $this->assertSame(DeliveryStage::DELIVERED, $this->stageOf($challan), '⛔ হাতে হাতে দেওয়া হলো, অথচ মাল "পৌঁছেছে" নয়।');
        $this->assertSame(1, GatePass::query()->where('delivery_challan_id', $challan->id)->count(), '⛔ গেট পাস নেই।');
        $this->assertSame(1, $this->billsOf($challan)->count(), '⛔ হাতে হাতে দেওয়ায় দ্বিতীয় বিল হয়েছে।');
        $this->assertSame((string) $this->customer->name_bn ?: (string) $this->customer->name_en,
            (string) DB::table('sal_delivery_events')->where('delivery_challan_id', $challan->id)
                ->where('to_stage', DeliveryStage::DELIVERED)->value('receiver_name'),
            '⛔ প্রাপক খালি ছিল, অথচ গ্রাহকের নাম বসেনি।');
    }

    /** ⭐ "পরে পাঠানো" (ডিফল্ট) — মাল অপেক্ষায়, গেট পাস নেই; রওনায় আসবে। */
    public function test_the_counter_sends_later_by_default(): void
    {
        $this->sell([])->assertSessionHasNoErrors();

        $challan = $this->challanOf(SalesInvoice::query()->latest('id')->firstOrFail());

        $this->assertNotSame(DeliveryStage::DELIVERED, $this->stageOf($challan), '⛔ "পরে পাঠানো" অথচ পৌঁছেছে বসেছে।');
        $this->assertSame(0, GatePass::query()->where('delivery_challan_id', $challan->id)->count(),
            '⛔ মাল গুদামেই, অথচ গেট পাস বেরিয়ে গেছে।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function confirmedChallan(): DeliveryChallan
    {
        $challans = app(DeliveryChallanService::class);

        return $challans->confirm($challans->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => $this->product->id, 'delivered_qty' => '5', 'rate' => '10']]));
    }

    /** @param  array<string, mixed>  $extra */
    private function sell(array $extra): TestResponse
    {
        return $this->from(route('sales.direct.create'))->post(route('sales.direct.store'), [
            'own_transport' => '1',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'deposit' => '50',
            'save_as_draft' => '0',
            'lines' => [['product_id' => $this->product->id, 'qty' => '5', 'rate' => '10']],
            ...$extra,
        ]);
    }

    /** @return Collection<int, SalesInvoice> বাতিল নয় এমন বিল, এই চালানের */
    private function billsOf(DeliveryChallan $challan): Collection
    {
        $ids = DB::table('sal_invoice_lines as il')
            ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
            ->where('cl.delivery_challan_id', $challan->id)
            ->distinct()->pluck('il.sales_invoice_id');

        return SalesInvoice::query()->whereIn('id', $ids)->where('status', '<>', DocumentStatus::CANCELLED)->get();
    }

    private function challanOf(SalesInvoice $bill): DeliveryChallan
    {
        $id = DB::table('sal_invoice_lines as il')
            ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
            ->where('il.sales_invoice_id', $bill->id)
            ->value('cl.delivery_challan_id');

        return DeliveryChallan::query()->findOrFail($id);
    }

    private function stageOf(DeliveryChallan $challan): string
    {
        return (string) DeliveryState::query()->where('delivery_challan_id', $challan->id)->value('stage');
    }
}
