<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\DataScope;
use App\Core\Services\NumberSeriesProvisioner;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Models\LedgerEntry;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ একটা কাগজ অন্য শাখায় নয় — RCV-0143 / মালিক: *"কোনোভাবেই অন্য শাখায় নয়"*।
 *
 * ⓘ যা ভেঙেছিল: উপরের শাখা-বাছাই একজন ব্যবহারকারীর সব ট্যাবে একটাই (`users.current_branch_id`)। A-র ট্যাবে ফর্ম খোলা,
 * আরেক ট্যাবে উপরে B বাছা — জমা দিলে কাগজ, তার নম্বর, খাতার সারি আর ভাউচার B-তে বসত, অথচ মাল A-র গুদাম থেকে, পক্ষ A-র।
 * ⭐ এখন কাগজের শাখা তার নোঙর থেকে (গুদাম, চালান, বিল, পক্ষ), আর নম্বর সেই শাখার নিজের সিরিজ থেকে।
 *
 * ⓘ দৃশ্য সবখানে একই, আসল দরজা দিয়ে: মালিক উপরে B (নেত্রকোনা) বেছে "সব শাখা" দেখছেন, আর A-র (ময়মনসিংহ) গুদাম বা পক্ষের
 * কাগজ জমা দিচ্ছেন। ⚠️ একটা শাখা দেখলে গুদামের খোঁজ নিজেই A-র গুদাম লুকায় — সেটা কাকতালীয় পাহারা, তাই দৃশ্যটা "সব শাখা"-তে।
 * নম্বরের দাবির জন্য A আর B দুই শাখার নিজের সিরিজ বসানো থাকে; কোন সিরিজ থেকে নম্বর এল তা ইস্যুর খাতা বলে।
 *
 * ec, ১৯ অক্টোবর ২০২৬।
 */
final class APaperLandsInItsOwnBranchTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $a;

    private Branch $b;

    private Warehouse $inA;

    private Warehouse $inB;

    private Customer $customer;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->a = $this->branch('MMS');
        $this->b = $this->branch('NTK');

        CompanyContext::set($this->company->id, $this->a->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->inA = $this->warehouse('WH-MMS');
        $this->inB = $this->warehouse('WH-NTK');

        // ⓘ A-র পক্ষ — লাইভের Super-এর গ্রাহক
        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['branch_id' => $this->a->id, 'credit_limit' => '1000000000'])->save();
        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->supplier->forceFill(['branch_id' => $this->a->id])->save();

        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
    }

    public function test_a_challan_and_a_goods_receipt_take_their_number_from_their_warehouses_branch(): void
    {
        $this->seriesInBothBranches('DRF', 'GRN');

        $orders = app(SalesOrderService::class);
        $order = $orders->confirm($orders->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->inA->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'ordered_qty' => '1', 'rate' => '10']],
        ));

        $this->headerOn($this->b);

        $this->post(route('sales.challan.store'), [
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->inA->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => '1',
            'lines' => [['product_id' => $this->product->id, 'sales_order_line_id' => $order->lines()->value('id'),
                'delivered_qty' => '1', 'rate' => '10']],
        ])->assertSessionHasNoErrors();

        $challan = DeliveryChallan::acrossBranches()->latest('id')->firstOrFail();
        $this->assertSame((int) $this->a->id, (int) $challan->branch_id);
        $this->assertSame((int) $this->a->id, $this->seriesBranchOf($challan->document_no), '⛔ A-র গুদামের চালানের নম্বর B-র সিরিজ থেকে');

        $this->post(route('purchase.receipt.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->inA->id,
            'trx_date' => now()->toDateString(),
            'supplier_challan_no' => 'BR-PIN-1',
            'lines' => [['product_id' => $this->product->id, 'received_qty' => '5', 'rate' => '8']],
        ])->assertSessionHasNoErrors();

        $receipt = PurchaseReceipt::acrossBranches()->latest('id')->firstOrFail();
        $this->assertSame((int) $this->a->id, (int) $receipt->branch_id);
        $this->assertSame((int) $this->a->id, $this->seriesBranchOf($receipt->document_no), '⛔ A-র গুদামের GRN-এর নম্বর B-র সিরিজ থেকে');
    }

    public function test_a_bill_and_a_return_from_a_warehouse_in_a_land_in_a_with_their_numbers_and_books(): void
    {
        $this->seriesInBothBranches('DRF', 'SR');

        // ⓘ A-র গুদাম থেকে পাকা চালান — উপরে তখনো A
        $challans = app(DeliveryChallanService::class);
        $challan = $challans->confirm($challans->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->inA->id, 'trx_date' => now()->toDateString(), 'own_transport' => true],
            [['product_id' => $this->product->id, 'delivered_qty' => '2', 'rate' => '10']],
        ));

        $this->headerOn($this->b);

        // ── বিল ──────────────────────────────────────────────────────────
        $line = $challan->lines()->firstOrFail();
        $this->post(route('sales.invoice.store'), [
            'delivery_challan_id' => $challan->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->inA->id,
            'trx_date' => now()->toDateString(),
            'lines' => [['product_id' => $line->product_id, 'delivery_challan_line_id' => $line->id, 'qty' => '2', 'rate' => '10']],
        ])->assertSessionHasNoErrors();

        $invoice = SalesInvoice::acrossBranches()->latest('id')->firstOrFail();
        $this->assertSame((int) $this->a->id, (int) $invoice->branch_id, '⛔ A-র মালের বিল উপরের B-তে বসল');
        $this->assertSame((int) $this->a->id, $this->seriesBranchOf($invoice->document_no), '⛔ বিলের খসড়া নম্বর B-র সিরিজ থেকে');

        $this->post(route('sales.invoice.confirm', $invoice))->assertSessionHasNoErrors();
        $this->assertSame('confirmed', SalesInvoice::acrossBranches()->findOrFail($invoice->id)->status, 'দৃশ্যটাই বানানো যায়নি — বিল পাকা হয়নি');
        $this->assertSame([(int) $this->a->id], $this->ledgerBranches(SalesInvoice::drillSourceType(), (int) $invoice->id),
            '⛔ বিলের পাওনা আর মজুদের সারি A-র বাইরে');

        // ── ফেরত ─────────────────────────────────────────────────────────
        $this->post(route('sales.return.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->inA->id,
            'sales_invoice_id' => $invoice->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'sales_invoice_line_id' => $invoice->lines()->value('id'), 'qty' => '1', 'rate' => '10']],
        ])->assertSessionHasNoErrors();

        $return = SalesReturn::acrossBranches()->latest('id')->firstOrFail();
        $this->assertSame((int) $this->a->id, (int) $return->branch_id, '⛔ A-র গুদামে ফেরা মালের ফেরত উপরের B-তে বসল');
        $this->assertSame((int) $this->a->id, $this->seriesBranchOf($return->document_no), '⛔ ফেরতের নম্বর B-র সিরিজ থেকে');

        $this->post(route('sales.return.confirm', $return))->assertSessionHasNoErrors();
        $this->assertSame([(int) $this->a->id], $this->ledgerBranches(SalesReturn::drillSourceType(), (int) $return->id),
            '⛔ ফেরতের পাওনা আর মজুদের সারি A-র বাইরে');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /**
     * ⓘ আসল পথে উপরের শাখা বদল — আগে B বাছা, তারপর "সব শাখা" দেখা (কাজের শাখা B-ই থাকে)।
     */
    private function headerOn(Branch $branch): void
    {
        $this->actingAs($this->owner->fresh())->post(route('branch.switch'), ['branch_id' => (string) $branch->id])->assertRedirect();
        $this->actingAs($this->owner->fresh())->post(route('branch.switch'), ['branch_id' => 'all'])->assertRedirect();

        $this->owner = $this->owner->fresh();
        $this->assertSame((int) $branch->id, (int) $this->owner->current_branch_id, 'দৃশ্যটাই বানানো যায়নি — উপরে B বসেনি');
        $this->assertTrue((bool) $this->owner->view_all_branches, 'দৃশ্যটাই বানানো যায়নি — "সব শাখা" দেখা চালু হয়নি');

        CompanyContext::set($this->company->id, $branch->id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    /**
     * ⓘ A আর B দুই শাখার নিজের সিরিজ — কোম্পানি-ব্যাপী সারির নকল, অনেক দূরের ক্রম থেকে (পুরনো নম্বরে ধাক্কা নয়)।
     */
    private function seriesInBothBranches(string ...$types): void
    {
        $year = FinancialYear::forDate(Carbon::today());
        app(NumberSeriesProvisioner::class)->provision($year);

        foreach ($types as $type) {
            $base = NumberSeries::query()->withoutGlobalScopes()
                ->where('company_id', $this->company->id)
                ->where('doc_type', $type)
                ->whereNull('branch_id')
                ->where('financial_year_id', $year?->id)
                ->firstOrFail();

            foreach ([[$this->a, 500000], [$this->b, 700000]] as [$branch, $from]) {
                $exists = NumberSeries::query()->withoutGlobalScopes()
                    ->where('company_id', $this->company->id)
                    ->where('doc_type', $type)
                    ->where('branch_id', $branch->id)
                    ->where('financial_year_id', $year?->id)
                    ->exists();

                if (! $exists) {
                    $copy = $base->replicate(['public_id']);
                    $copy->forceFill(['branch_id' => $branch->id, 'next_number' => (int) $base->next_number + $from])->save();
                }
            }
        }
    }

    /** কোন শাখার সিরিজ থেকে এই নম্বর এল — ইস্যুর খাতা থেকে */
    private function seriesBranchOf(string $documentNo): ?int
    {
        $seriesId = IssuedNumber::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('document_no', $documentNo)
            ->latest('id')
            ->value('number_series_id');

        $branch = NumberSeries::query()->withoutGlobalScopes()->whereKey($seriesId)->value('branch_id');

        return $branch === null ? null : (int) $branch;
    }

    /** @return list<int> এই কাগজের খাতার সারিগুলোর শাখা */
    private function ledgerBranches(string $sourceType, int $sourceId): array
    {
        return LedgerEntry::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->pluck('branch_id')
            ->map(fn ($branch) => (int) $branch)
            ->unique()
            ->values()
            ->all();
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function warehouse(string $code): Warehouse
    {
        return Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
