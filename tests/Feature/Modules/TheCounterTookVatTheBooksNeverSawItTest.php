<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Tax;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টারে ভ্যাট নেওয়া হলে খাতাতেও ভ্যাট বসে।
 *
 * ── কী ভাঙা ছিল, ৩১ আগস্ট ২০২৬ ───────────────────────────────────────
 * সরাসরি বিক্রয়ের পর্দা ভ্যাট গুনত আর "নিট প্রদেয়"-তে যোগ করত, তাই
 * বিক্রেতা ভ্যাটসহ টাকাটাই নিতেন। কিন্তু ওই ঘরটার HTML-এ কোনো `name=`
 * ছিল না — ছাড়, খরচ, রাউন্ডিং, জমা সবের ছিল, ভ্যাটের ছিল না। অর্থাৎ
 * সংখ্যাটা **কখনো সার্ভারে পৌঁছাত না**, আর
 * [[SalesInvoiceService]] পড়ত `$line['tax'] ?? '0'`।
 *
 * ফল: বিলে ভ্যাট শূন্য। জমা বিলের মোটের চেয়ে বেশি হওয়ায় বাড়তিটা
 * "ফেরত" ধরা হত — টাকাটা ড্রয়ারে থাকত, খাতায় নয়, আর দিনশেষের গণনায়
 * ড্রয়ার বেশি দেখাত। ছাপা রসিদেও ভ্যাটের সারিটা উঠত না, কারণ ওটা
 * কেবল `tax > 0` হলে ছাপা হয় — তাই পর্দা আর কাগজ দুইটা আলাদা কথা বলত।
 *
 * ── কেন [[DirectSaleTest]] এটা ধরেনি ─────────────────────────────────
 * ডেমো ডেটার কোনো পণ্যে কর বসানো নেই, তাই ওখানে ভ্যাট সবসময় শূন্যই —
 * আর শূন্য পাওয়া মানে ঠিক পাওয়া, না ভুল পাওয়া, সেটা আলাদা করা যেত না।
 * এই পরীক্ষাটা তাই প্রথমেই পণ্যে একটা **সত্যিকারের হার** বসায়।
 */
class TheCounterTookVatTheBooksNeverSawItTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function taxThe(Product $product, string $rate, bool $inclusive = false): Tax
    {
        $tax = Tax::create([
            'code' => $inclusive ? 'ZVATIN' : 'ZVAT15',
            'name_en' => $inclusive ? 'VAT inside price' : 'VAT 15%',
            'name_bn' => $inclusive ? 'দামের ভেতরে ভ্যাট' : 'ভ্যাট ১৫%',
            'rate' => $rate,
            'kind' => 'vat',
            'is_inclusive' => $inclusive,
        ]);

        $product->forceFill(['tax_id' => $tax->id])->save();

        return $tax;
    }

    /** মাল ঢোকানো — যে পথে সত্যিই ঢোকে। */
    private function stockUp(string $qty = '100'): void
    {
        $bill = app(PurchaseBillService::class)->create(
            [
                'supplier_id' => Supplier::query()->value('id'),
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '50']],
        );

        app(PurchaseBillService::class)->confirm($bill);

        /*
         * আর মালটা বুঝে নেওয়া — Stock Placement, ৪ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ এই ফাইলের প্রশ্ন ভ্যাট নিয়ে, মজুদ নিয়ে নয় — কিন্তু ভ্যাট
         * পরীক্ষা করতে হলে আগে কাউন্টারে বেচতে পারতে হবে। ⛔ ধাপটা ছাড়া
         * বিক্রয়ই আটকাত, আর ভ্যাটের নিয়মটা কখনো পরীক্ষা হত না।
         */
        app(StockService::class)->place(
            product: $this->product,
            warehouse: $this->warehouse,
            qty: $qty,
            sourceType: PurchaseBill::STOCK_SOURCE,
            sourceId: $bill->id,
        );
    }

    /** @return array{challan: mixed, invoice: mixed, change: string} */
    private function sell(string $qty = '10', string $rate = '100'): array
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate, 'free_qty' => '0']],
        );
    }

    /**
     * ১০ × ১০০ = ১,০০০, তার উপর ১৫% = ১৫০, মোট ১,১৫০।
     *
     * কাউন্টারের পর্দা ঠিক এই সংখ্যাগুলোই দেখায়। আগে বিলে বসত
     * ভ্যাট ০ আর মোট ১,০০০ — অর্থাৎ প্রতি বিক্রিতে ১৫০ টাকা খাতার
     * বাইরে থাকত।
     */
    public function test_a_taxed_product_puts_its_vat_on_the_invoice(): void
    {
        // মাল আগে ঢোকে, কর পরে বসে — নাহলে ক্রয় বিলটাও ভ্যাট নিত আর
        // পরীক্ষাটা দুইটা জিনিস একসাথে মাপত
        $this->stockUp();
        $this->taxThe($this->product, '15');

        $invoice = $this->sell()['invoice']->fresh(['lines']);

        $this->assertSame('150.0000', (string) $invoice->tax,
            'কাউন্টারে ভ্যাট নেওয়া হয়, তাই বিলেও ভ্যাট বসতে হবে।');

        $this->assertSame('1150.0000', (string) $invoice->total,
            'মোট = ছাড়ের পরের টাকা + ভ্যাট।');

        $this->assertSame('150.0000', (string) $invoice->lines->first()->tax);
    }

    /**
     * কর না বসানো পণ্যে কিছুই বদলায় না।
     *
     * ভ্যাট না দেওয়া ডিপোর প্রতিটা বিলে হঠাৎ একটা সংখ্যা বসে গেলে
     * সেটা এই সারানোর চেয়ে বড় ক্ষতি হত।
     */
    public function test_a_product_without_a_tax_still_carries_none(): void
    {
        $this->stockUp();

        $invoice = $this->sell()['invoice'];

        $this->assertSame('0.0000', (string) $invoice->tax);
        $this->assertSame('1000.0000', (string) $invoice->total);
    }

    /**
     * দামের ভেতরের ভ্যাটে মোট বাড়ে না — কর আলাদা করে লেখা হয়।
     *
     * ১১৫ টাকায় ১৫% ভেতরে থাকলে কর ১৫, আর গ্রাহক ১১৫-ই দেন।
     * `net + tax` লিখলে ১৩২.২৫ হয়ে যেত, অর্থাৎ দুইবার ভ্যাট।
     */
    public function test_a_tax_inside_the_price_does_not_raise_the_total(): void
    {
        $this->stockUp();
        $this->taxThe($this->product, '15', inclusive: true);

        $invoice = $this->sell(qty: '1', rate: '115')['invoice'];

        $this->assertSame('115.0000', (string) $invoice->total,
            'ভেতরের ভ্যাটে মোট বাড়ে না — দরেই ওটা আছে।');

        $this->assertSame('15.0000', (string) $invoice->tax,
            'তবু কতটুকু কর, সেটা খাতায় আলাদা করে থাকতে হবে।');
    }

    /**
     * হাতে লেখা অঙ্ক থাকলে সেটাই মানা হয়।
     *
     * নথির পর্দাগুলোয় ভ্যাটের ঘর সত্যিই আছে, আর সেখানে ব্যবহারকারীর
     * টাইপ করা সংখ্যা নীরবে বদলে দেওয়া মানে তাঁর সিদ্ধান্ত মুছে ফেলা।
     * শূন্যও একটা সিদ্ধান্ত — "পাঠায়নি" নয়।
     */
    public function test_an_amount_typed_by_hand_wins_over_the_rate(): void
    {
        $this->stockUp();
        $this->taxThe($this->product, '15');

        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100', 'tax' => '0']],
        );

        $this->assertSame('0.0000', (string) $invoice->tax,
            'কেউ ইচ্ছা করে ০ লিখলে সেটা মানা হবে, হার দিয়ে ঢেকে দেওয়া হবে না।');
    }
}
