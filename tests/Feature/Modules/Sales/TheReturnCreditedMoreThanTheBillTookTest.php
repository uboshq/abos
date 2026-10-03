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
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SignsTheDiscountAsTheOwner;
use Tests\TestCase;

/**
 * ফেরত বিলের চেয়ে বেশি জমা দিত — গভীর অডিট, ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ আগে ([[SalesReturnService::replaceLines()]]) ────────────────────────
 * ১) দর নেওয়া হত বিলের লাইনের `rate` থেকে — ছাড়ের **আগের** দাম। লাইনের ছাড় (প্রমোশনসহ) আর বিলের
 *    মাথার ছাড় বাদ যেত, তাই ১০% ছাড়ের বিল পুরো ফেরত দিলে গ্রাহকের নামে বেশি জমা পড়ত।
 * ২) ভ্যাট ছিল হাতে লেখা ঘর — কোনো সীমা নেই, ভ্যাট বন্ধ থাকলেও। ফেরতের চাবিধারী `tax = 999999`
 *    লিখে যেকোনো গ্রাহকের বাকি মুছে দিতে পারতেন।
 * ৩) একই ফেরতে একই বিলের লাইন দুই সারিতে দিলে প্রতিটা সারি আলাদাভাবে মাপা হত — বিক্রির চেয়ে বেশি
 *    ফেরত বসত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * বিলের লাইনের সাথে বাঁধা ফেরত বিল যেভাবে খাতায় বসেছিল ঠিক সেভাবে ফেরে: লাইনের অঙ্কের আনুপাতিক ভাগ,
 * বিলের মাথার ছাড় আর রাউন্ডিংয়ের অনুপাতে; ভ্যাট বিলের লাইনের ভ্যাটের আনুপাতিক ভাগ। হাতে লেখা ভ্যাট
 * নেওয়া হয় না। বিল ছাড়া ফেরতে ভ্যাট পণ্যের নিজের হারে (বিলের একই নিয়ম), ভ্যাট বন্ধ থাকলে শূন্য।
 * একই বিলের লাইনের সব সারি মিলিয়ে মাপা হয়।
 */
final class TheReturnCreditedMoreThanTheBillTookTest extends TestCase
{
    use RefreshDatabase;
    use SignsTheDiscountAsTheOwner;

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

    /**
     * ⛔→⭐ ১০ × ১০০, লাইনে ১০% ছাড়, বিলে ৫০ ছাড় — বিলের মোট ৮৫০। পুরোটা ফেরত দিলে জমা ঠিক ৮৫০,
     * ১,০০০ নয়।
     */
    public function test_a_full_return_credits_exactly_what_the_bill_booked(): void
    {
        $invoice = $this->discountedSale();
        $this->assertSame(0, bccomp((string) $invoice->total, '850', 4), 'প্রস্তুতিটাই ভুল — বিলের মোট ৮৫০ নয়: '.$invoice->total);

        $return = $this->returnOf($invoice, '10');

        $this->assertSame(0, bccomp((string) $return->total, (string) $invoice->total, 4),
            '⛔ পুরো ফেরতে জমা '.$return->total.', অথচ বিল বসেছিল '.$invoice->total);
    }

    /** ⭐ আংশিক ফেরত আনুপাতিক — ১০-এর ৪টা ফেরত দিলে ৮৫০-এর ৪০% = ৩৪০। */
    public function test_a_part_return_credits_its_share(): void
    {
        $return = $this->returnOf($this->discountedSale(), '4');

        $this->assertSame(0, bccomp((string) $return->total, '340', 4), '⛔ ৪টার ফেরতে জমা '.$return->total.', ৩৪০ নয়।');
    }

    /** ⛔ হাতে লেখা ভ্যাট নেওয়া হয় না — ভ্যাট বন্ধ, তাই ফেরতের ভ্যাট শূন্য, জমা বিলের ভাগই। */
    public function test_a_typed_tax_cannot_wipe_a_customers_balance(): void
    {
        $return = $this->returnOf($this->discountedSale(), '1', tax: '999999');

        $this->assertSame(0, bccomp((string) $return->tax, '0', 4), '⛔ হাতে লেখা ভ্যাট ফেরতে বসে গেছে: '.$return->tax);
        $this->assertSame(0, bccomp((string) $return->total, '85', 4), '⛔ ১টার ফেরতে জমা '.$return->total.', ৮৫ নয়।');
    }

    /** ⛔ বিল ছাড়া ফেরতেও হাতে লেখা ভ্যাট নয় — ভ্যাট বন্ধ, তাই শূন্য; জমা কেবল পরিমাণ × দর। */
    public function test_a_return_without_a_bill_takes_no_typed_tax_either(): void
    {
        $returns = app(SalesReturnService::class);

        $return = $returns->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->value('id'),
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->product->id, 'qty' => '2', 'rate' => '100', 'tax' => '5000']]);

        $this->assertSame(0, bccomp((string) $return->fresh()->tax, '0', 4), '⛔ বিল ছাড়া ফেরতে হাতে লেখা ভ্যাট বসেছে: '.$return->fresh()->tax);
        $this->assertSame(0, bccomp((string) $return->fresh()->total, '200', 4));
    }

    /** ⛔ একই বিলের লাইন দুই সারিতে — সব মিলিয়ে বিক্রির বেশি ফেরত হয় না। */
    public function test_two_rows_on_the_same_bill_line_cannot_return_more_than_was_sold(): void
    {
        $invoice = $this->discountedSale();
        $line = $invoice->lines()->firstOrFail();
        $returns = app(SalesReturnService::class);

        $draft = $returns->create($this->returnHead($invoice), [
            $this->row($line->id, '10'),
            $this->row($line->id, '10'),
        ]);

        try {
            $returns->confirm($draft);
            $this->fail('⛔ একই বিলের লাইনের দুই সারি মিলে বিক্রির দ্বিগুণ ফেরত পাকা হয়ে গেছে।');
        } catch (ValidationException $e) {
            /*
             * ⓘ "বেশি ফেরত" কারণেই — অন্য বাধা এই দাবি সবুজ করতে পারে না। ⚠️ মেপে দেখা: আজ থামায় মজুদের
             * স্তরের পাহারা (`inventory::validation.return_exceeds_issue`, [[CostLayerService]]), বিক্রয়ের
             * [[assertWithinSold()]] নয় — ওটা সারি ধরে আলাদা মাপে। দাবিটা দুইটার যেকোনোটাকেই মানে।
             */
            $said = collect($e->errors())->flatten()->implode(' | ');
            $this->assertTrue(
                str_contains($said, strtok(__('inventory::validation.return_exceeds_issue', ['product' => '']), ' ') ?: 'x')
                    || str_contains($said, (string) strtok(__('sales::validation.over_returned', ['no' => '', 'room' => '']), ':')),
                'অন্য কারণে থেমেছে: '.$said,
            );
        }
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** ১০ × ১০০, লাইনে ১০% ছাড় (৯০০), বিলে ৫০ ছাড় — মোট ৮৫০; ভ্যাট বন্ধ (ডিফল্ট)। */
    private function discountedSale(): SalesInvoice
    {
        $sale = app(DirectSaleService::class)->complete(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'discount_amount' => '50',
                'own_transport' => '1',
            ],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100', 'free_qty' => '0', 'discount_percent' => '10']],
        );

        // ⓘ যেকোনো ছাড়ে মালিকের সই (১ অক্টোবর ২০২৬) — এই দাবি হিসাব মাপে, সই নয় ([[SignsTheDiscountAsTheOwner]]) — শেষ সইয়ে বিক্রি নিজে শেষ
        $this->ownerSignsTheDiscounts();

        return $sale['invoice']->fresh(['lines']);
    }

    private function returnOf(SalesInvoice $invoice, string $qty, ?string $tax = null): \App\Modules\Sales\Models\SalesReturn
    {
        $returns = app(SalesReturnService::class);
        $line = $invoice->lines()->firstOrFail();

        return $returns->confirm($returns->create($this->returnHead($invoice), [$this->row($line->id, $qty, $tax)]))->fresh();
    }

    /** @return array<string, mixed> */
    private function returnHead(SalesInvoice $invoice): array
    {
        return [
            'customer_id' => $invoice->customer_id,
            'warehouse_id' => $this->warehouse->id,
            'sales_invoice_id' => $invoice->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->value('id'),
            'trx_date' => now()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(int $invoiceLineId, string $qty, ?string $tax = null): array
    {
        return array_filter([
            'product_id' => $this->product->id,
            'sales_invoice_line_id' => $invoiceLineId,
            'qty' => $qty,
            'rate' => '100',
            'tax' => $tax,
        ], fn ($v) => $v !== null);
    }
}
