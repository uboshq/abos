<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Sales\Models\CommissionClaim;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CommissionClaimService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * যে কমিশন ডিপো আগে দেয়, আর কোম্পানির কাছে পরে দাবি করে।
 *
 * ── কেন এটা ছাড় নয় ─────────────────────────────────────────────────
 * টাকাটা ডিপোর পকেট থেকে যাচ্ছে না, কোম্পানির পকেট থেকে — ডিপো কেবল
 * আগে দিয়ে দিচ্ছে। ছাড় হিসেবে লিখলে বিক্রয় কমে যেত, আর **৪% মার্জিনের
 * ব্যবসায় ৫% কমিশন মানে খাতা বলত লোকসানে বেচছি** — অথচ কোম্পানির কাছে
 * পাওনাটা কোথাও দেখা যেত না।
 *
 * এই পরীক্ষার কেন্দ্রীয় দাবিটাই সেটা: কমিশন দিলেও **মার্জিন অটুট
 * থাকে**, আর দাবিটা সম্পদ হয়ে দাঁড়ায়।
 */
class TheCommissionIPayFirstTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $principal;

    private Customer $dealer;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->principal = Supplier::query()->firstOrFail();
        $this->dealer = Customer::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        // সীমা দুইটা যথেষ্ট উঁচুতে, যাতে পরীক্ষাগুলো সীমায় না আটকায়
        app(SettingsService::class)->set('sales.commission_max_amount', 100000);
        app(SettingsService::class)->set('sales.commission_max_percent', 60);
    }

    private function service(): CommissionClaimService
    {
        return app(CommissionClaimService::class);
    }

    /** একটা বিক্রয় — ১০টা @ ১৭৯.৪৪, ক্রয় ছিল ১৭২.৫৪। */
    private function anInvoice(): SalesInvoice
    {
        $product = Product::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'TOFAN-C',
            'name_en' => 'Tofan',
            'name_bn' => 'তুফান',
            'unit_id' => Product::query()->value('unit_id'),
            'purchase_price' => '172.54',
            'sale_price' => '179.44',
            'is_active' => true,
        ]);

        $receipt = app(PurchaseReceiptService::class)->confirm(
            app(PurchaseReceiptService::class)->create(
                ['supplier_id' => $this->principal->id, 'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString()],
                [['product_id' => $product->id, 'received_qty' => '10', 'rate' => '172.54']],
            ),
        );

        /*
         * মালটা বুঝে নেওয়া হয় — Stock Placement, ৪ সেপ্টেম্বর ২০২৬।
         *
         * রিসিভ মানে গাড়ি থেকে নামল; বিক্রয়যোগ্য হতে হলে কাউকে বুঝে
         * নিতে হয়। ⛔ এই লাইনটা ছাড়া নিচের বিক্রয়টা "তাকে যথেষ্ট নেই"
         * বলে আটকাবে — অর্থাৎ ধাপটা আলংকারিক নয়।
         */
        app(StockService::class)->place(
            product: $product,
            warehouse: $this->warehouse,
            qty: '10',
            sourceType: PurchaseReceipt::STOCK_SOURCE,
            sourceId: $receipt->id,
        );

        return app(SalesInvoiceService::class)->confirm(
            app(SalesInvoiceService::class)->create(
                ['customer_id' => $this->dealer->id, 'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString()],
                [['product_id' => $product->id, 'qty' => '10', 'rate' => '179.44']],
            ),
        );
    }

    private function balanceOf(string $code): string
    {
        return (string) (LedgerEntry::query()
            ->where('account_id', StandardChart::find($code)->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')
            ->value('bal') ?? '0');
    }

    private function dealerDue(): string
    {
        return (string) (LedgerEntry::query()
            ->where('party_type', 'customer')
            ->where('party_id', $this->dealer->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as due')
            ->value('due') ?? '0');
    }

    // ── কেন্দ্রীয় দাবি ───────────────────────────────────────────────

    /**
     * ৫% কমিশন দিলেও বিক্রয় ও মার্জিন এক চুলও নড়ে না।
     *
     * বিল ১,৭৯৪.৪০ · খরচ ১,৭২৫.৪০ · মার্জিন ৬৯.০০
     * কমিশন ৫% = ৮৯.৭২ — ডিলারের দেনা কমে, দাবিটা সম্পদ হয়।
     *
     * ছাড় হিসেবে লিখলে বিক্রয় হত ১,৭০৪.৬৮ আর মার্জিন **−২০.৭২**।
     */
    public function test_the_margin_survives_the_commission(): void
    {
        $invoice = $this->anInvoice();

        $marginBefore = bcsub((string) $invoice->total, (string) $invoice->cost_of_goods, 4);
        $this->assertSame(0, bccomp($marginBefore, '69.00', 2), 'শুরুর মার্জিনটাই ভুল।');

        $this->service()->create([
            'customer_id' => $this->dealer->id,
            'supplier_id' => $this->principal->id,
            'sales_invoice_id' => $invoice->id,
            'rate_percent' => '5',
        ]);

        $fresh = $invoice->fresh();

        $this->assertSame(0, bccomp((string) $fresh->total, (string) $invoice->total, 4),
            'কমিশন বিলের অঙ্ক বদলে দিয়েছে — ওটা ছাড় হয়ে গেছে।');

        $this->assertSame(0, bccomp(
            bcsub((string) $fresh->total, (string) $fresh->cost_of_goods, 4), '69.00', 2,
        ), 'কমিশন দেওয়ার পর মার্জিন বদলে গেছে।');
    }

    /** দাবিটা সম্পদ হয়ে বসে, আর ডিলারের দেনা ততটাই কমে। */
    public function test_the_claim_is_an_asset_and_the_dealer_owes_less(): void
    {
        $invoice = $this->anInvoice();
        $dueBefore = $this->dealerDue();

        $this->service()->create([
            'customer_id' => $this->dealer->id,
            'supplier_id' => $this->principal->id,
            'sales_invoice_id' => $invoice->id,
            'rate_percent' => '5',
        ]);

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::COMMISSION_CLAIM), '89.72', 2),
            'কোম্পানির কাছে দাবির খাতে অঙ্কটা বসেনি।');

        $this->assertSame(0, bccomp(bcsub($dueBefore, $this->dealerDue(), 4), '89.72', 2),
            'ডিলারের দেনা কমেনি — কমিশনটা তাঁর হিসাবে যায়নি।');
    }

    /**
     * কোম্পানি মানলে দাবির খাত খালি হয়, আর ওদের দেনা কমে।
     *
     * কোনো টাকা নড়ে না — দুইটা খাতা নড়ে।
     */
    public function test_settling_moves_it_onto_the_principal(): void
    {
        $invoice = $this->anInvoice();

        $claim = $this->service()->create([
            'customer_id' => $this->dealer->id,
            'supplier_id' => $this->principal->id,
            'sales_invoice_id' => $invoice->id,
            'rate_percent' => '5',
        ]);

        $owedBefore = (string) (LedgerEntry::query()
            ->where('party_type', 'supplier')->where('party_id', $this->principal->id)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as owed')->value('owed') ?? '0');

        $settled = $this->service()->settle($claim);

        $this->assertSame(CommissionClaim::SETTLED, $settled->status);

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::COMMISSION_CLAIM), '0', 2),
            'সমন্বয়ের পরেও দাবির খাতে টাকা পড়ে আছে।');

        $owedAfter = (string) (LedgerEntry::query()
            ->where('party_type', 'supplier')->where('party_id', $this->principal->id)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as owed')->value('owed') ?? '0');

        $this->assertSame(0, bccomp(bcsub($owedBefore, $owedAfter, 4), '89.72', 2),
            'কোম্পানির কাছে দেনা কমেনি।');
    }

    /**
     * কোম্পানি না মানলে দাবিটা খরচ হয়ে যায়।
     *
     * এই পথটা না থাকলে অনাদায়ী দাবি বছরের পর বছর সম্পদ হয়ে বসে থাকত,
     * আর ব্যালেন্স শিট এমন পাওনা দেখাত যা কেউ কোনোদিন দেবে না।
     */
    public function test_a_refused_claim_becomes_an_expense(): void
    {
        $invoice = $this->anInvoice();

        $claim = $this->service()->create([
            'customer_id' => $this->dealer->id,
            'supplier_id' => $this->principal->id,
            'sales_invoice_id' => $invoice->id,
            'rate_percent' => '5',
        ]);

        $this->service()->reject($claim, 'কোম্পানি বলেছে এই ডিলারের চুক্তিতে কমিশন নেই');

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::COMMISSION_CLAIM), '0', 2),
            'নামঞ্জুরের পরেও দাবির খাতে টাকা পড়ে আছে।');

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::COMMISSION_WRITTEN_OFF), '89.72', 2),
            'প্রত্যাখ্যাত কমিশন খরচে বসেনি।');
    }

    /** কারণ ছাড়া নামঞ্জুর করা যায় না। */
    public function test_refusing_needs_a_reason(): void
    {
        $claim = $this->service()->create([
            'customer_id' => $this->dealer->id,
            'supplier_id' => $this->principal->id,
            'base_amount' => '1000',
            'rate_percent' => '5',
        ]);

        $this->expectException(ValidationException::class);

        $this->service()->reject($claim, '   ');
    }

    /** একবার সিদ্ধান্ত হয়ে গেলে দ্বিতীয়বার নয়। */
    public function test_a_decided_claim_is_not_decided_again(): void
    {
        $claim = $this->service()->create([
            'customer_id' => $this->dealer->id,
            'supplier_id' => $this->principal->id,
            'base_amount' => '1000',
            'rate_amount' => '50',
        ]);

        $this->service()->settle($claim);

        $this->expectException(ValidationException::class);

        $this->service()->settle($claim->fresh());
    }

    // ── দুইটা সীমা ───────────────────────────────────────────────────

    /** টাকার সীমা ছাড়ালে আটকায়। */
    public function test_the_taka_limit_stops_it(): void
    {
        app(SettingsService::class)->set('sales.commission_max_amount', 5000);
        $this->actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail());

        $this->expectException(ValidationException::class);

        $this->service()->create([
            'customer_id' => $this->dealer->id,
            'supplier_id' => $this->principal->id,
            'base_amount' => '500000',
            'rate_percent' => '2',   // ২% হলেও অঙ্কটা ১০,০০০
        ]);
    }

    /**
     * আর হারের সীমাও আলাদা করে আটকায়।
     *
     * ছোট বিলে ৫০% কমিশন অঙ্কে ছোট, তাই টাকার সীমা ওটা ধরত না — অথচ
     * হারটাই প্রশ্ন তোলে। দুইটা সীমা তাই দুইটাই লাগে।
     */
    public function test_the_percent_limit_stops_what_the_taka_limit_misses(): void
    {
        app(SettingsService::class)->set('sales.commission_max_amount', 5000);
        app(SettingsService::class)->set('sales.commission_max_percent', 10);
        $this->actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail());

        $this->expectException(ValidationException::class);

        $this->service()->create([
            'customer_id' => $this->dealer->id,
            'supplier_id' => $this->principal->id,
            'base_amount' => '1000',
            'rate_percent' => '50',   // মাত্র ৫০০ টাকা, তবু ৫০%
        ]);
    }

    /** সীমা ছাড়ানোর অনুমতি থাকলে ৫০%-ও বসে — নিষেধ নয়, সইয়ের প্রশ্ন। */
    public function test_with_the_override_fifty_percent_is_allowed(): void
    {
        app(SettingsService::class)->set('sales.commission_max_percent', 10);

        $claim = $this->service()->create([
            'customer_id' => $this->dealer->id,
            'supplier_id' => $this->principal->id,
            'base_amount' => '1000',
            'rate_percent' => '50',
        ]);

        $this->assertSame(0, bccomp((string) $claim->amount, '500', 2));
    }

    /**
     * বিক্রয়কর্মী নিজের কমিশন নিজে অনুমোদন করতে পারেন না।
     *
     * ঢালাও `sales.%` নিয়মটা এই প্রকল্পে **তিনবার** নতুন ঘোষিত
     * অনুমতি নীরবে বিক্রয়কর্মীকে দিয়ে দিয়েছে। এবার সারিটা ঘোষণার
     * সাথেই বসানো, আর এই পরীক্ষাটা সেটাই পাহারা দেয়।
     */
    public function test_the_salesman_never_gets_the_override(): void
    {
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->assertFalse($salesman->can('sales.commission.override'),
            'বিক্রয়কর্মী কমিশনের সীমা ছাড়ানোর ক্ষমতা পেয়ে গেছেন।');
    }

    // ── অঙ্কটা কীভাবে এল ────────────────────────────────────────────

    /** থোক টাকা দিলে হার লাগে না, আর ভিত্তিটাও জমা থাকে। */
    public function test_a_flat_amount_is_taken_as_it_is(): void
    {
        $claim = $this->service()->create([
            'customer_id' => $this->dealer->id,
            'supplier_id' => $this->principal->id,
            'base_amount' => '4000',
            'rate_amount' => '250',
        ]);

        $this->assertSame(0, bccomp((string) $claim->amount, '250', 2));
        $this->assertSame(0, bccomp((string) $claim->base_amount, '4000', 2));
    }

    /** হারও নয়, টাকাও নয় — তখন কিছুই বসে না। */
    public function test_without_a_rate_or_an_amount_nothing_is_recorded(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->create([
            'customer_id' => $this->dealer->id,
            'supplier_id' => $this->principal->id,
            'base_amount' => '1000',
        ]);
    }

    // ── পর্দা ───────────────────────────────────────────────────────

    /** পর্দাটা খোলে, আর অপেক্ষমাণ যোগফলটা দেখায়। */
    public function test_the_screen_shows_what_is_still_claimed(): void
    {
        $this->service()->create([
            'customer_id' => $this->dealer->id,
            'supplier_id' => $this->principal->id,
            'base_amount' => '4000',
            'rate_amount' => '250',
        ]);

        $this->get(route('sales.commission.index'))
            ->assertOk()
            ->assertSee(__('sales::field.commission_pending_total'))
            ->assertSee('250.00');
    }

    /** পর্দা থেকেই কমিশন বসানো যায়, আর খতিয়ানে পৌঁছায়। */
    public function test_a_commission_can_be_recorded_from_the_screen(): void
    {
        $this->post(route('sales.commission.store'), [
            'trx_date' => now()->toDateString(),
            'customer_id' => $this->dealer->id,
            'supplier_id' => $this->principal->id,
            'base_amount' => '10000',
            'rate_percent' => '5',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::COMMISSION_CLAIM), '500', 2));
    }

    /** সিদ্ধান্তটাও সারি থেকেই — আর কারণ ছাড়া নামঞ্জুর আটকায়। */
    public function test_the_row_decides_and_a_refusal_needs_its_reason(): void
    {
        $claim = $this->service()->create([
            'customer_id' => $this->dealer->id,
            'supplier_id' => $this->principal->id,
            'base_amount' => '1000',
            'rate_amount' => '80',
        ]);

        $this->post(route('sales.commission.reject', $claim))
            ->assertSessionHasErrors('decision_reason');

        $this->post(route('sales.commission.settle', $claim))
            ->assertSessionHasNoErrors();

        $this->assertSame(CommissionClaim::SETTLED, $claim->fresh()->status);
    }

    /** যিনি কমিশন দেখতে পারেন না, তাঁর কাছে পর্দাটাই নেই। */
    public function test_a_user_without_the_permission_is_refused(): void
    {
        $this->actingAs(User::query()->where('email', 'accounts@abos.test')->firstOrFail());

        $this->get(route('sales.commission.index'))->assertForbidden();
    }
}
