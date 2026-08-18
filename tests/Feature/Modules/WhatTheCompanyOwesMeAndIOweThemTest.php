<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Dashboard\SupplierWidgets;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কোম্পানির কাছে আমার কত, আমার কাছে কোম্পানির কত।
 *
 * ── কোন প্রশ্নের কাগজ ───────────────────────────────────────────────
 * সুপার ডিপো মাস শেষে কোম্পানির লেজারের সাথে নিজের খাতা মেলায়। ওই
 * মুহূর্তে চারটা সংখ্যা লাগে: কত টাকার মাল এল, তার কতটা বিক্রি হলো,
 * মার্জিন কত দাঁড়াল, আর ওদের কত দেওয়া হলো।
 *
 * আজ পর্যন্ত ওই চারটা পেতে চার-পাঁচটা রিপোর্ট মিলিয়ে হাতে গুনতে হত,
 * আর তর্কটা বাধত ঠিক সেখানেই।
 *
 * ── সবচেয়ে সূক্ষ্ম জায়গাটা ─────────────────────────────────────────
 * "কার মাল বিক্রি হলো" — পণ্যের সাথে সরবরাহকারীর কোনো যোগ নেই, আর
 * থাকা উচিতও নয়। সম্পর্কটা টানা হয় FIFO স্তর ধরে, তাই এই পরীক্ষায়
 * **দুই কোম্পানির মাল** ঢোকানো হয় আর দেখা হয় প্রতিটা সারিতে ঠিক
 * তারটাই বসেছে কি না।
 */
class WhatTheCompanyOwesMeAndIOweThemTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $alin;

    private Supplier $star;

    private Warehouse $warehouse;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->dealer = Customer::query()->firstOrFail();

        $suppliers = Supplier::query()->orderBy('id')->take(2)->get();
        $this->alin = $suppliers->first();
        $this->star = $suppliers->last();

        $this->assertNotSame($this->alin->id, $this->star->id,
            'পরীক্ষাটার জন্য দুইটা আলাদা সরবরাহকারী দরকার।');
    }

    /** একটা টাটকা পণ্য — ডেমোর মজুদ ও স্তর ছাড়া, যাতে অঙ্কটা পরিষ্কার থাকে। */
    private function product(string $code): Product
    {
        return Product::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => 'Tofan '.$code,
            'name_bn' => 'তুফান '.$code,
            'unit_id' => Product::query()->value('unit_id'),
            'purchase_price' => '172.54',
            'sale_price' => '179.44',
            'is_active' => true,
        ]);
    }

    /** ওই কোম্পানির মাল গুদামে ঢোকানো — ডিপো প্রাইসে। */
    private function receive(Supplier $from, Product $product, string $qty, string $rate): void
    {
        app(PurchaseReceiptService::class)->confirm(
            app(PurchaseReceiptService::class)->create(
                ['supplier_id' => $from->id, 'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString()],
                [['product_id' => $product->id, 'received_qty' => $qty, 'rate' => $rate]],
            ),
        );
    }

    /** ডিলারের কাছে বিক্রি — ডিলার প্রাইসে। */
    private function sell(Product $product, string $qty, string $rate): void
    {
        app(SalesInvoiceService::class)->confirm(
            app(SalesInvoiceService::class)->create(
                ['customer_id' => $this->dealer->id, 'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString()],
                [['product_id' => $product->id, 'qty' => $qty, 'rate' => $rate]],
            ),
        );
    }

    /** @return array<int, object> সরবরাহকারীর id ধরে সারিগুলো */
    private function settlement(): array
    {
        $result = app(ReportEngine::class)->run('supplier.settlement', [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);

        $rows = [];

        foreach ($result->rows as $row) {
            $row = (object) $row;
            $rows[(int) $row->supplier_id] = $row;
        }

        return $rows;
    }

    /**
     * এক কোম্পানির মাল এল, বিক্রি হলো — চারটা সংখ্যাই ঠিক বসে।
     *
     * ১০টা @ ১৭২.৫৪ ঢুকল = ১,৭২৫.৪০
     * ৪টা @ ১৭৯.৪৪ বিক্রি = ৭১৭.৭৬, খরচ ৪ × ১৭২.৫৪ = ৬৯০.১৬
     * মার্জিন = ২৭.৬০ (ক্রয়মূল্যের ঠিক ৪%)
     */
    public function test_the_four_numbers_of_the_month(): void
    {
        $tofan = $this->product('TOFAN-1');

        $this->receive($this->alin, $tofan, '10', '172.54');
        $this->sell($tofan, '4', '179.44');

        $row = $this->settlement()[$this->alin->id] ?? null;

        $this->assertNotNull($row, 'আলিন ফুডের সারিটাই রিপোর্টে নেই।');

        $this->assertSame(0, bccomp((string) $row->goods_in, '1725.40', 2),
            'মাল আসার অঙ্কটা ভুল।');

        $this->assertSame(0, bccomp((string) $row->sold, '717.76', 2),
            'তার মালের বিক্রয়ের অঙ্কটা ভুল।');

        $this->assertSame(0, bccomp((string) $row->cost_of_sold, '690.16', 2),
            'বিক্রীত মালের ব্যয়ের অঙ্কটা ভুল।');

        $this->assertSame(0, bccomp((string) $row->margin, '27.60', 2),
            'মার্জিনের অঙ্কটা ভুল — ১৭২.৫৪-এর ৪% হওয়ার কথা।');
    }

    /**
     * দুই কোম্পানির মাল আলাদা করে গোনা হয় — এটাই আসল পরীক্ষা।
     *
     * ── কেন এটা সহজে ভুল হয় ─────────────────────────────────────────
     * পণ্যের সাথে সরবরাহকারীর যোগ থাকলে অঙ্কটা "কাছাকাছি" হত: একই
     * পণ্য দুই কোম্পানি থেকে এলে সবটা একজনের নামে বসত। এখানে ঠিক ওই
     * অবস্থাটাই বানানো হয়েছে — দুইটা আলাদা পণ্য, দুই কোম্পানির, আর
     * প্রতিটা সারিতে নিজেরটাই থাকতে হবে।
     */
    public function test_two_principals_never_bleed_into_each_other(): void
    {
        $one = $this->product('TOFAN-A');
        $two = $this->product('CHIPS-B');

        $this->receive($this->alin, $one, '10', '100');
        $this->receive($this->star, $two, '10', '200');

        $this->sell($one, '2', '104');   // আলিন: বিক্রয় ২০৮, খরচ ২০০
        $this->sell($two, '3', '208');   // স্টার: বিক্রয় ৬২৪, খরচ ৬০০

        $rows = $this->settlement();

        $this->assertSame(0, bccomp((string) $rows[$this->alin->id]->sold, '208', 2));
        $this->assertSame(0, bccomp((string) $rows[$this->alin->id]->margin, '8', 2));

        $this->assertSame(0, bccomp((string) $rows[$this->star->id]->sold, '624', 2));
        $this->assertSame(0, bccomp((string) $rows[$this->star->id]->margin, '24', 2));
    }

    /**
     * এখনো কত দিতে বাকি — খতিয়ান থেকেই।
     *
     * চালানে মাল এলে GRNI দায় জন্মায়, তাই ১,৭২৫.৪০ ওদের পাওনা।
     */
    public function test_what_is_still_owed_comes_from_the_ledger(): void
    {
        $tofan = $this->product('TOFAN-2');

        $this->receive($this->alin, $tofan, '10', '172.54');

        $row = $this->settlement()[$this->alin->id] ?? null;

        $this->assertNotNull($row);
        $this->assertSame(0, bccomp((string) $row->still_owed, '1725.40', 2),
            'এখনো দিতে বাকি অঙ্কটা খতিয়ানের সাথে মেলে না।');
    }

    /**
     * এক বিক্রয় দুই স্তর টানলেও বিক্রয়ের অঙ্ক দ্বিগুণ হয় না।
     *
     * ── কেন এই পরীক্ষাটা আলাদা করে ──────────────────────────────────
     * আগের পরীক্ষাগুলোয় প্রতিটা বিক্রয় একটাই স্তর টানত, তাই
     * `SUM(লাইনের amount)` আর `SUM(দর × স্তরের পরিমাণ)` একই উত্তর দিত।
     * অর্থাৎ ভুল যোগফল বসিয়ে দিলেও সবগুলো সবুজই থাকত — **পাহারাটা সত্যি
     * ছিল কেবল যতক্ষণ সমস্যাটা অনুপস্থিত।** সরিয়ে দেখেই ধরা পড়ল।
     *
     * এখানে দুই দরে মাল আসে, আর এক বিক্রয় দুইটা স্তরই টানে: ৬টা @ ১০০
     * আর ৪টা @ ১১০, মোট ১০টা বিক্রি ১২০ দরে।
     *
     *   ঠিক উত্তর  → বিক্রয় ১,২০০ · খরচ ১,০৪০ · মার্জিন ১৬০
     *   ভুল উত্তর  → বিক্রয় ২,৪০০ (লাইনের অঙ্কটা দুইবার গোনা)
     */
    public function test_one_sale_across_two_layers_is_not_counted_twice(): void
    {
        $tofan = $this->product('TOFAN-5');

        $this->receive($this->alin, $tofan, '6', '100');
        $this->receive($this->alin, $tofan, '4', '110');

        $this->sell($tofan, '10', '120');

        $row = $this->settlement()[$this->alin->id] ?? null;

        $this->assertNotNull($row);

        $this->assertSame(0, bccomp((string) $row->sold, '1200', 2),
            'এক বিক্রয় দুই স্তর টানায় বিক্রয়ের অঙ্কটা দ্বিগুণ গোনা হয়েছে।');

        $this->assertSame(0, bccomp((string) $row->cost_of_sold, '1040', 2),
            'দুই স্তরের খরচ যোগ হয়নি — ৬×১০০ + ৪×১১০ হওয়ার কথা।');

        $this->assertSame(0, bccomp((string) $row->margin, '160', 2));
    }

    // ── হোম পর্দার সংখ্যা দুইটা ──────────────────────────────────────

    /** "কোম্পানিকে দিতে হবে" সংখ্যাটা সত্যিই ওঠে, আর ঠিক অঙ্কে। */
    public function test_the_widget_says_what_is_owed(): void
    {
        $this->receive($this->alin, $this->product('TOFAN-3'), '10', '172.54');

        $owed = collect(SupplierWidgets::widgets())
            ->firstWhere('label', __('supplier::widget.owed_to_principals'));

        $this->assertNotNull($owed, 'কোম্পানিকে দিতে হবে — উইজেটটাই নেই।');
        $this->assertStringContainsString('1,725', $owed->value);
    }

    /**
     * মার্জিনের উইজেটে শতাংশটা ক্রয়মূল্যের উপর।
     *
     * বিক্রয়ের উপর গুনলে ৪% হত ৩.৮৫%, আর মাস শেষে ডিপো ভাবত কোম্পানি
     * কম দিয়েছে। একই অঙ্ক, দুই রকম পড়া — তর্কটা ঠিক ওখানেই বাধে।
     */
    public function test_the_margin_widget_counts_on_cost(): void
    {
        $tofan = $this->product('TOFAN-4');

        $this->receive($this->alin, $tofan, '10', '172.54');
        $this->sell($tofan, '4', '179.44');

        $margin = collect(SupplierWidgets::widgets())
            ->firstWhere('label', __('supplier::widget.margin_this_month'));

        $this->assertNotNull($margin, 'মার্জিনের উইজেটটাই নেই।');
        $this->assertStringContainsString('4.00', (string) $margin->hint,
            'শতাংশটা ক্রয়মূল্যের উপর গোনা হয়নি।');
    }

    /** কিছু না ঘটলে উইজেট দুইটাই চুপ থাকে — শূন্যের কার্ড পর্দার জায়গা নেয়। */
    public function test_the_widgets_stay_quiet_when_there_is_nothing(): void
    {
        $this->assertSame([], SupplierWidgets::widgets());
    }
}
