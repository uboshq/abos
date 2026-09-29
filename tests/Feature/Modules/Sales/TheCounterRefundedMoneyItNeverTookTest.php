<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টার এমন টাকা ফেরত দিত যা কখনো নেয়নি, আর অফিসের খসড়া বিল তুলে নিয়ে পাকা করত (গভীর অডিট,
 * ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * ১) কাউন্টারের ফেরত (`sales.pos.return`) চাইত কেবল `sales.pos` — অথচ ফেরত বিক্রির চাবি নয়
 *    ([[TheCounterReturnDoorWasNeverKnockedOnTest]]-এর আগের দাবি ঠিক এটাকেই পাকা করে রেখেছিল)।
 * ২) "টাকা ফেরত" দিলে ফেরতের পুরো অঙ্ক ড্রয়ার থেকে বেরোত — বিলে কত শোধ হয়েছিল না দেখেই। বাকির
 *    বিলের মাল ফেরতেও নগদ বেরোত, আর গ্রাহকের পাওনা আবার বাড়ত।
 * ৩) `resumed_invoice_id`-এ যেকোনো খসড়া বিল চলত — অফিসের খসড়াও ক্যাশিয়ার তুলে নিয়ে বদলে পাকা করতে পারতেন।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * ফেরতের দরজা `sales.return.create`ও চায়; নগদ ফেরত কেবল যতটা শোধ বাকির বেশি হয়ে গেছে (আদায় − (মোট −
 * ফেরত)) ততটা; কাউন্টার তুলতে পারে কেবল কাউন্টারে রাখা (`parked_at`) বা নিজের বানানো খসড়া।
 */
final class TheCounterRefundedMoneyItNeverTookTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $cashier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->cashier = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->cashier->companies()->attach($this->company->id, ['is_active' => true]);
        $this->cashier->givePermissionTo(['sales.pos', 'sales.return.create']);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();

        app(SettingsService::class)->set('sales.screen_pos', true);
    }

    /** ⛔ বাকির বিল, কিছুই শোধ নয় — ফেরতে নগদ একটুও বেরোয় না, কেবল পাওনা কমে। */
    public function test_nothing_is_refunded_on_a_bill_nobody_paid(): void
    {
        $invoice = $this->creditSale();
        $payments = Voucher::query()->where('type', Voucher::PAYMENT)->count();

        $this->takeBack($invoice, '4');

        $this->assertSame(1, SalesReturn::query()->where('sales_invoice_id', $invoice->id)->count(), 'ফেরতটাই হয়নি।');
        $this->assertSame($payments, Voucher::query()->where('type', Voucher::PAYMENT)->count(),
            '⛔ যে বিলের এক টাকাও শোধ হয়নি, তার ফেরতে ড্রয়ার থেকে নগদ বেরিয়েছে।');
    }

    /** ⭐ নগদে পুরো শোধ — ফেরত যাওয়া অংশটুকুই ফেরত (২টার ১টা = ১৫০)। */
    public function test_a_paid_bill_refunds_just_what_came_back(): void
    {
        $invoice = $this->paidAtTheCounter();

        $this->takeBack($invoice, '1');

        $refund = Voucher::query()->where('type', Voucher::PAYMENT)->latest('id')->first();
        $this->assertNotNull($refund, '⛔ শোধ হওয়া বিলের ফেরতে টাকা ফেরত যায়নি।');
        $this->assertSame(0, bccomp((string) $refund->amount, '150', 4), '⛔ ফেরত গেছে '.$refund->amount.', ১৫০ নয়।');
    }

    /**
     * ⭐ আংশিক শোধ — ১,০০০-এর বিলে ৭০০ শোধ, ৪টা (৪০০) ফেরত: বাকি ছিল ৩০০, ফেরত ৪০০, তাই শোধের বাড়তি
     * কেবল ১০০ — নগদ ফেরতও ঠিক ১০০, ৪০০ নয়।
     */
    public function test_a_part_paid_bill_refunds_only_the_overpaid_part(): void
    {
        $invoice = $this->creditSale();

        $this->actingAs($this->owner);
        $collections = app(\App\Modules\Sales\Services\CollectionService::class);
        $collections->confirm($collections->create([
            'customer_id' => $invoice->customer_id,
            'trx_date' => now()->toDateString(),
            'amount' => '700',
        ], [['sales_invoice_id' => $invoice->id, 'amount' => '700']]));

        $this->takeBack($invoice, '4');

        $refund = Voucher::query()->where('type', Voucher::PAYMENT)->latest('id')->first();
        $this->assertNotNull($refund, '⛔ শোধের বাড়তি থাকা সত্ত্বেও কিছুই ফেরত যায়নি।');
        $this->assertSame(0, bccomp((string) $refund->amount, '100', 4), '⛔ ফেরত গেছে '.$refund->amount.', ১০০ নয়।');
    }

    /** ⛔ অফিসের খসড়া বিল কাউন্টার তুলে নিতে পারে না — বিলটা খসড়াই, সারিগুলো অক্ষত। */
    public function test_the_counter_cannot_take_over_an_office_draft(): void
    {
        $this->actingAs($this->owner);
        $draft = app(SalesInvoiceService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'qty' => '3', 'rate' => '77']]);

        $this->actingAs($this->cashier->fresh())->post(route('sales.pos.checkout'), [
            'resumed_invoice_id' => $draft->id,
            'warehouse_id' => $this->warehouse->id,
            'paid' => '10',
            'lines' => [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '10']],
        ]);

        $draft->refresh();
        $this->assertSame(DocumentStatus::DRAFT, $draft->status, '⛔ কাউন্টার অফিসের খসড়া বিলটা পাকা করে দিয়েছে।');
        $this->assertSame(0, bccomp((string) $draft->lines()->value('qty'), '3', 4), '⛔ অফিসের খসড়ার সারি বদলে গেছে।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** ১০ × ১০০ = ১,০০০, বাকিতে, কোনো জমা নয়। */
    private function creditSale(): SalesInvoice
    {
        $this->actingAs($this->owner);

        return app(DirectSaleService::class)->complete(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => $this->warehouse->id, 'own_transport' => '1'],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100', 'free_qty' => '0']],
        )['invoice']->fresh();
    }

    /** কাউন্টারে ২ × ১৫০, পুরো ৩০০ নগদে। */
    private function paidAtTheCounter(): SalesInvoice
    {
        $this->actingAs($this->owner)->post(route('sales.pos.checkout'), [
            'warehouse_id' => $this->warehouse->id,
            'paid' => '300',
            'lines' => [['product_id' => $this->product->id, 'qty' => '2', 'rate' => '150']],
        ])->assertSessionHasNoErrors();

        return SalesInvoice::query()->latest('id')->firstOrFail();
    }

    private function takeBack(SalesInvoice $invoice, string $qty): void
    {
        $this->actingAs($this->cashier->fresh())->post(route('sales.pos.return'), [
            'document_no' => $invoice->document_no,
            'warehouse_id' => $this->warehouse->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->value('id'),
            'refund' => '1',
            'lines' => [['product_id' => $this->product->id, 'qty' => $qty, 'sales_invoice_line_id' => $invoice->lines()->value('id')]],
        ])->assertSessionHasNoErrors();
    }
}
