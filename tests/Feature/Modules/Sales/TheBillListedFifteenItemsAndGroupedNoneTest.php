<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Print\PrintableDocument;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Brand;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * বিলে পনেরোটা পণ্য থাকত, আর কোনো ভাগ থাকত না।
 *
 * ── ⭐ মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * তিনি Univer-এর একটা বিল পাঠিয়ে বললেন: *"এই রকম একটি ফরমেট রাখ যাতে
 * ব্যান্ড ওয়াইজ দেখা যায়"*।
 *
 * ⓘ ঐ কাগজে সারিগুলো ব্র্যান্ড ধরে দল বাঁধা, আর প্রতিটা দলের শেষে একটা
 * করে উপ-মোট — *"Jabed Food Sub.Total: 46,665.02"*।
 *
 * ── ⚠️ কেন এটা সাজসজ্জা নয় ──────────────────────────────────────────
 * ডিলারের বিলে ত্রিশ-চল্লিশটা সারি থাকে, আর টাকা মেলানো হয়
 * **ব্র্যান্ড ধরে** — কোন কোম্পানির মাল কত গেল। ⛔ ভাগ না থাকলে সেটা
 * হাতে যোগ করতে হয়, আর হাতের যোগে ভুল হয়।
 */
final class TheBillListedFifteenItemsAndGroupedNoneTest extends TestCase
{
    use RefreshDatabase;

    private SalesInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->invoice = $this->aBillOfFourItems();
    }

    /**
     * চারটা পণ্যের একটা বিল, দুইটা ব্র্যান্ডে ভাগ করা।
     *
     * ⚠️ ডেমোতে কোনো বিক্রয় বিল নেই, তাই বানিয়ে নিতে হয় — আর বানানো
     * হয় **আসল পথেই** ([[DirectSaleService]]), হাতে সারি বসিয়ে নয়।
     * ⓘ হাতে বসালে দাবিটা ছাপার কাগজ মাপত, কিন্তু বিলটা বাস্তবে যেভাবে
     * তৈরি হয় সেভাবে তৈরি হত না।
     */
    private function aBillOfFourItems(): SalesInvoice
    {
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();

        $products = Product::query()->orderBy('id')->take(4)->get();

        $this->assertCount(4, $products, 'ডেমোতে চারটা পণ্যও নেই — পরীক্ষাটা কিছুই দেখছে না।');

        /* ⓘ প্রথম দুইটা এক ব্র্যান্ডে, পরের দুইটা অন্যটায় — পাশাপাশি */
        foreach (['ব্র্যান্ড-ক', 'ব্র্যান্ড-খ'] as $at => $name) {
            $brand = Brand::query()->create([
                'code' => 'BR'.$at, 'name_en' => 'Brand '.$at, 'name_bn' => $name, 'is_active' => true,
            ]);

            foreach ($products->slice($at * 2, 2) as $product) {
                $product->brand_id = $brand->id;
                $product->save();
            }
        }

        $stock = app(StockService::class);
        $lines = [];

        foreach ($products as $at => $product) {
            $stock->move(
                product: $product, warehouse: $warehouse,
                sourceType: 'purchase_bill', sourceId: 8001, unplaced: '50',
            );

            $stock->place(
                product: $product, warehouse: $warehouse,
                qty: '50', sourceType: 'purchase_bill', sourceId: 8001,
            );

            $lines[] = [
                'product_id' => $product->id,
                'qty' => (string) (2 + $at),
                'rate' => (string) (100 + $at * 10),
                'free_qty' => '0',
            ];
        }

        $sale = app(DirectSaleService::class)->complete(
            ['customer_id' => $customer->id, 'warehouse_id' => $warehouse->id],
            $lines,
        );

        return SalesInvoice::query()->with('lines.product')->findOrFail($sale['invoice']->id);
    }

    /**
     * ⭐ প্রতিটা ব্র্যান্ডের শেষ সারিতে তার উপ-মোট বসে।
     */
    public function test_each_brand_closes_with_its_own_subtotal(): void
    {
        $lines = $this->linesOnThePaper();

        $closers = collect($lines)->filter(fn (array $l) => ($l['band_total'] ?? '') !== '');

        $this->assertCount(2, $closers, 'উপ-মোটের সারি দুইটা হওয়ার কথা, কারণ ব্র্যান্ড দুইটা।');

        $this->assertSame(
            ['ব্র্যান্ড-ক', 'ব্র্যান্ড-খ'],
            $closers->pluck('group')->values()->all(),
            'উপ-মোটগুলো ভুল ব্র্যান্ডের নামে বসেছে।',
        );
    }

    /**
     * ⛔ আর **প্রতিটা** উপ-মোট তার নিজের ব্র্যান্ডের যোগফল।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * উপরেরটা কেবল গোনে *"দুইটা সারি এসেছে"*। ⛔ সংখ্যাটা শূন্য হলেও,
     * কিংবা গোটা বিলের মোট বসালেও ওটা সবুজ থাকত।
     *
     * ── ⛔ আর কেন **সবগুলো**, প্রথমটা নয় ────────────────────────────
     * ⚠️ প্রথমে কেবল প্রথম ব্র্যান্ডটা মাপা হত, আর মিউট্যান্ট চালিয়ে
     * ধরা পড়ল দাবিটা **পার পেয়ে যায়**: যোগফলটা দল বদলে রিসেট না করলেও
     * প্রথম ব্র্যান্ডের সংখ্যা ঠিকই থাকে — ভুলটা দেখা যায় দ্বিতীয়টায়,
     * যেখানে প্রথমটার টাকাও যোগ হয়ে বসে।
     *
     * ⓘ অর্থাৎ নমুনাটাই ভুল ছিল — ঠিক সেই দলের একটা যেখানে বাগটা
     * অদৃশ্য।
     */
    public function test_every_subtotal_is_the_sum_of_its_own_brands_lines(): void
    {
        $lines = $this->invoice->fresh('lines.product')->lines;

        $expected = $lines
            ->groupBy(fn ($line) => (string) $line->product->brandRow?->name())
            ->map(fn ($rows) => Money::format(
                $rows->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->amount, 4), '0')
            ));

        $shown = collect($this->linesOnThePaper())
            ->filter(fn (array $l) => ($l['band_total'] ?? '') !== '')
            ->pluck('band_total', 'group');

        $this->assertSame(
            $expected->all(),
            $shown->all(),
            'কোনো একটা ব্র্যান্ডের উপ-মোট তার নিজের সারিগুলোর যোগফল নয়।',
        );
    }

    /**
     * ⭐ কয়টা পণ্য আর মোট কত মাল — বিলের মাথায়।
     *
     * ── ⓘ মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────────
     * তাঁর পাঠানো বিলে উপরে লেখা থাকে *"Total Item: 15"* আর
     * *"Delivery Qty. 656"*। ⚠️ ডিলারের কাছে মাল নামানোর সময় ওটাই
     * প্রথম মিলিয়ে দেখা হয়।
     *
     * ── ⚠️ দাবিটা সংখ্যা ধরে, ঘর আছে কি না ধরে নয় ───────────────────
     * ⛔ কেবল চাবিটা আছে কি না দেখলে শূন্য বসে থাকলেও সবুজ থাকত, আর
     * কাগজে *"মোট আইটেম: ০"* ছাপা হত — যা কোনো বিলেই সত্যি নয়।
     */
    public function test_the_head_carries_the_item_count_and_the_quantity(): void
    {
        $meta = $this->paperOf()->meta;

        $this->assertSame('4', $meta['sales::print.total_item'] ?? null, 'পণ্যের সংখ্যাটা ভুল।');

        /* ⓘ সারিগুলো ২ · ৩ · ৪ · ৫ — মোট ১৪ */
        $this->assertSame('14', $meta['sales::print.delivery_qty'] ?? null, 'মোট পরিমাণটা ভুল।');
    }

    /*
     * ⛔ সরু কাগজে ভাগটা আঁকা হয় না — তবে দাবিটা এখানে নেই, ইচ্ছাকৃত।
     *
     * ⓘ আঁকার দিকটা [[print/document-body]]-তে, আর ঐ ফাইলটা এখন
     * abos-8b-র `PrintProfile`-এর কাজের মধ্যে — তাঁরা আমার সারিটা
     * নিজেদের `$banded` শর্তের নিচে জুড়ে নিয়েছেন, আর সেখানেই সরু
     * কাগজের ছাড়টা বসানো।
     *
     * ⚠️ ঐ শর্তটা ধরে দাবি লিখলে সেটা **তাঁদের কমিট-না-করা কাজের উপর
     * নির্ভর করত** — আজ সবুজ, অথচ নতুন চেকআউটে লাল। ⓘ তাই এই ফাইলের
     * দাবিগুলো কেবল কন্ট্রোলারের ফল মাপে, যেটা আমার নিজের।
     */

    /**
     * কাগজে যে সারিগুলো গেল — কন্ট্রোলারের আসল ফল।
     *
     * ⚠️ কাগজটা PDF, তাই ছাপা লেখা ধরে মাপা যায় না। ⓘ তাই ভিউ তৈরির
     * মুহূর্তে ডেটাটা ধরা হয় — ওটাই কন্ট্রোলার যা বানিয়েছে, হুবহু।
     *
     * @return list<array<string, mixed>>
     */
    private function linesOnThePaper(): array
    {
        $seen = null;

        View::composer('print.*', function ($view) use (&$seen) {
            $seen ??= $view->getData()['doc'] ?? null;
        });

        $this->get(route('sales.print.invoice', ['invoice' => $this->invoice->id]))->assertOk();

        $this->assertNotNull($seen, 'ছাপার পর্দাটা কোনো কাগজ পায়নি।');

        return $seen->lines;
    }

    /** গোটা কাগজটা — মাথা ও সারি দুইটাই দরকার হলে। */
    private function paperOf(): PrintableDocument
    {
        $seen = null;

        View::composer('print.*', function ($view) use (&$seen) {
            $seen ??= $view->getData()['doc'] ?? null;
        });

        $this->get(route('sales.print.invoice', ['invoice' => $this->invoice->id]))->assertOk();

        $this->assertNotNull($seen, 'ছাপার পর্দাটা কোনো কাগজ পায়নি।');

        return $seen;
    }
}
