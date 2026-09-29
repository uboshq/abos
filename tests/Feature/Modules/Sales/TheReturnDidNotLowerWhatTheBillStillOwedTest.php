<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ফেরতের পরেও বিলের বাকি কমত না — তাই একই টাকা দুইবার নেওয়া যেত (গভীর অডিট, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⛔ আগে ([[SalesInvoice::dueAmount()]]) ────────────────────────────────
 * বাকি = মোট − আদায়। পাকা ফেরত খাতায় পাওনা কমায়, কিন্তু বিলের বাকি থেকে বাদ যেত না। ১,০০০-এর বিলে
 * ৪০০-র ফেরতের পরেও আদায়ের পর্দা ১,০০০ নিত — গ্রাহক ৪০০ বেশি দিতেন, খাতায় সেটা "অগ্রিম" হয়ে থাকত,
 * আর তাগাদার তালিকা পুরো বিল বকেয়া দেখাত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * বাকি = মোট − আদায় − পাকা ফেরত (শূন্যের নিচে নয়), একক পাতায় আর তালিকায় ([[scopeWithCollected()]])
 * একই হিসাবে।
 */
final class TheReturnDidNotLowerWhatTheBillStillOwedTest extends TestCase
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

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
    }

    /** ⛔→⭐ ১,০০০-এর বিল, ৪০০ ফেরত — বাকি ৬০০, একক পাতায় আর তালিকায় একই। */
    public function test_a_return_lowers_what_the_bill_still_owes(): void
    {
        $invoice = $this->sale();
        $this->returnOf($invoice, '4');

        $this->assertSame(0, bccomp($invoice->fresh()->dueAmount(), '600', 4), '⛔ ফেরতের পরেও বাকি '.$invoice->fresh()->dueAmount());

        $listed = SalesInvoice::query()->withCollected()->whereKey($invoice->id)->firstOrFail();
        $this->assertSame(0, bccomp($listed->dueAmount(), '600', 4), '⛔ তালিকায় বাকি '.$listed->dueAmount().', একক পাতায় ৬০০।');
    }

    /** ⛔ ফেরতের পরে বিলের পুরো টাকা আদায় নেওয়া যায় না — বাকির বেশি বলে ফেরে। */
    public function test_the_returned_part_cannot_be_collected_again(): void
    {
        $invoice = $this->sale();
        $this->returnOf($invoice, '4');

        $this->expectException(ValidationException::class);

        app(CollectionService::class)->create([
            'customer_id' => $this->customer->id,
            'trx_date' => now()->toDateString(),
            'amount' => '1000',
        ], [['sales_invoice_id' => $invoice->id, 'amount' => '1000']]);
    }

    /** ⓘ পুরো শোধ, তারপর ফেরত — বাকি শূন্য, ঋণাত্মক নয়। */
    public function test_a_paid_bill_with_a_return_owes_nothing_not_less_than_nothing(): void
    {
        $invoice = $this->sale();
        $collections = app(CollectionService::class);
        $collections->confirm($collections->create([
            'customer_id' => $this->customer->id,
            'trx_date' => now()->toDateString(),
            'amount' => '1000',
        ], [['sales_invoice_id' => $invoice->id, 'amount' => '1000']]));

        $this->returnOf($invoice, '4');

        $this->assertSame(0, bccomp($invoice->fresh()->dueAmount(), '0', 4));
    }

    /**
     * ⭐ খাতা আর বিল একই কথা বলে — প্রতিটা ধাপে খাতায় গ্রাহকের পাওনা যতটা বদলায়, তাঁর বিলগুলোর বাকির
     * যোগও ঠিক ততটা (বিক্রি, ফেরত, আদায়)। ⓘ বদলটা মাপা হয়, অঙ্কটা নয় — ডেমো গ্রাহকের আগের জের থাকে।
     */
    public function test_the_ledger_and_the_bills_move_together(): void
    {
        [$ledger0, $bills0] = $this->standing();

        $invoice = $this->sale();
        $this->assertMovedTogether($ledger0, $bills0, '1000', 'বিক্রির পরে');

        $this->returnOf($invoice, '4');
        $this->assertMovedTogether($ledger0, $bills0, '600', 'ফেরতের পরে');

        $collections = app(CollectionService::class);
        $collections->confirm($collections->create([
            'customer_id' => $this->customer->id,
            'trx_date' => now()->toDateString(),
            'amount' => '600',
        ], [['sales_invoice_id' => $invoice->id, 'amount' => '600']]));
        $this->assertMovedTogether($ledger0, $bills0, '0', 'আদায়ের পরে');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} খাতায় পাওনা, আর পাকা বিলগুলোর বাকির যোগ */
    private function standing(): array
    {
        $bills = SalesInvoice::query()->where('customer_id', $this->customer->id)->where('status', 'confirmed')->get()
            ->reduce(fn (string $sum, SalesInvoice $i) => bcadd($sum, $i->dueAmount(), 4), '0');

        return [(string) $this->customer->fresh()->outstanding(), $bills];
    }

    private function assertMovedTogether(string $ledger0, string $bills0, string $expected, string $when): void
    {
        [$ledger, $bills] = $this->standing();

        $this->assertSame(0, bccomp(bcsub($ledger, $ledger0, 4), $expected, 4), "{$when}: খাতার পাওনা বদলেছে ".bcsub($ledger, $ledger0, 4));
        $this->assertSame(0, bccomp(bcsub($bills, $bills0, 4), $expected, 4), "⛔ {$when}: খাতা বদলেছে {$expected}, অথচ বিলের বাকি ".bcsub($bills, $bills0, 4));
    }


    /** ১০ × ১০০ = ১,০০০, বাকিতে (কোনো জমা নয়)। */
    private function sale(): SalesInvoice
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'own_transport' => '1'],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100', 'free_qty' => '0']],
        )['invoice']->fresh();
    }

    private function returnOf(SalesInvoice $invoice, string $qty): void
    {
        $returns = app(SalesReturnService::class);

        $returns->confirm($returns->create([
            'customer_id' => $invoice->customer_id,
            'warehouse_id' => $this->warehouse->id,
            'sales_invoice_id' => $invoice->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->value('id'),
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'sales_invoice_line_id' => $invoice->lines()->value('id'),
            'qty' => $qty,
        ]]));
    }
}
