<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ফ্রি কার্টনটা এল, ঢুকল, আর কোথাও দেখা গেল না।
 *
 * ── ⛔ মালিকের নির্দেশ, ১৮ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"ইনভেন্টরিতে স্টক আলাদা ম্যানেজ হওয়ার কথা ছিল, কিন্তু হচ্ছে না।
 * ইনভয়েস প্রিন্টিংয়েও আলাদা দেখানোর কথা — এগুলোর কোনোটাই দেখাচ্ছে না।"*
 *
 * ── ⓘ আর সবচেয়ে শেখার মতো ব্যাপারটা ─────────────────────────────────
 * **হিসাবটা আগে থেকেই আলাদা ছিল।** `inv_stock_movements`-এ
 * `free_change` আর `unplaced_free_change` কলাম দুইটা প্রথম দিন থেকে
 * আছে, [[StockService::statesForAll()]] ওগুলো গুনেও রাখত, আর
 * [[PurchaseBillService]] ফ্রি মালকে আলাদা খোপেই বসাত।
 *
 * ⛔ কেবল **কোনো পর্দা কোনোদিন জিজ্ঞেস করেনি**। ⓘ তাই ফ্রি মাল গুদামে
 * ঢুকত, খাতায় বসত, আর তালিকায় ও কাগজে অদৃশ্য থাকত — কোনো ত্রুটি ছাড়াই,
 * কারণ কিছুই ভাঙেনি। ⚠️ এই প্রকল্পের সবচেয়ে চেনা ফাঁদ।
 *
 * ── ⚠️ দ্বিতীয় অদৃশ্যতা, যেটা আরও বড় ────────────────────────────────
 * ক্রয় থেকে আসা মাল প্রথমে `unplaced` খোপে বসে, `floor`-এ নয় — লরি
 * থেকে নামা মাল আর তাকে তোলা মাল এক জিনিস নয়। ⛔ কিন্তু মজুদের তালিকা
 * কেবল `floor` গুনত, তাই **আজ পঞ্চাশ কার্টন এলে তালিকা শূন্য দেখাত**
 * যতক্ষণ না কেউ Stock Placement পর্দায় গিয়ে বসিয়ে আসেন।
 */
final class TheFreeCartonWasInvisibleEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    /**
     * ⭐ ৪৮ কেনা আর ৪ ফ্রি — তালিকায় দুইটা আলাদা সংখ্যা।
     *
     * ⚠️ দাবিটা রেন্ডার হওয়া পর্দার উপর, নিয়মের উপর নয়: সংখ্যাগুলো
     * সার্ভিসে আগেও ঠিক ছিল, কেবল পর্দায় পৌঁছাত না।
     */
    public function test_the_stock_list_counts_free_goods_on_their_own(): void
    {
        /*
         * ⛔ আগের সংখ্যাটা আগে পড়া হয় — ১৯ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ ডেমোর পণ্যটায় আগে থেকেই তাকে মাল আছে। ⛔ প্রথম লেখায়
         * দাবিটা *"তাকে শূন্য না হলে তাকের সংখ্যাটাই ৪৮"* ধরে নিত, আর
         * ঠিক কোডেও লাল হত। ⭐ তাই এখন মাপা হয় **পার্থক্য** — কেনার
         * আগে আর পরে, একই সারির একই ঘর।
         */
        $before = $this->stockNumbers();

        $this->receiveFortyEightAndFourFree();

        $page = $this->get(route('inventory.stock.index'));
        $page->assertOk();

        $html = $page->getContent();

        /*
         * ⛔ দাবিটা শিরোনামের **হুবহু লেখা** ধরে, `assertSee` ধরে নয়।
         *
         * ⚠️ প্রথমে `assertSee(__('inventory::field.free'))` লেখা ছিল, আর
         * পাহারাটা **কলাম সরিয়েও সবুজ থাকত** — কারণ "ফ্রি"
         * শব্দটা সাজানোর ড্রপডাউনের *"ফ্রি বেশি আগে"* লেখাতেও আছে।
         *
         * ⓘ abos-46-এর শিক্ষা হুবহু এই: পাহারা পর্দা মাপতে হয়,
         * আর পর্দা মানে এখানে **টেবিলের ঘর**, পাতার যেকোনো লেখা নয়।
         */
        $headers = $this->headersOf($html);

        foreach (['free', 'free_available', 'unplaced', 'unplaced_free'] as $field) {
            $this->assertContains(__('inventory::field.'.$field), $headers,
                '"'.__('inventory::field.'.$field).'" কলামটা টেবিলে নেই।');
        }

        $row = $this->stockRowFor($html, $this->product->code);

        $this->assertSameSize($headers, $row,
            'শিরোনাম আর ঘরের সংখ্যা মিলছে না — কোন সংখ্যা কোন কলামে বলা যাবে না।');

        /*
         * ⭐ আর এইটাই আসল দাবি: সংখ্যাটা **ঠিক সেই কলামে**।
         *
         * ⛔ আগে কেবল `assertContains('48.00', $row)` ছিল, আর সেটা
         * দুর্বল: বসানোর সুইচের অবস্থাভেদে মালটা `floor`-এও বসতে পারে,
         * আর তখন সংখ্যাটা অন্য ঘরে থেকেও দাবিটা পাস করাত।
         */
        $after = $this->stockNumbers();

        /*
         * ⭐ ফ্রি ৪টা ফ্রি-র ঘরগুলোতেই বেড়েছে, কেনা ৪৮টা কেনার ঘরগুলোতে।
         *
         * ⓘ "তাকে + বসেনি" একসাথে — বসানোর সুইচের অবস্থাভেদে মাল যেকোনো
         * একটায় বসে, আর দাবিটা সুইচ নিয়ে নয়, **ফ্রি আলাদা গোনা** নিয়ে।
         * ⛔ ফ্রি-র ৪টা কেনার ঘরে মিশে গেলে দুই দাবিই লাল হয়।
         */
        $this->assertSame('48.0000',
            bcsub(bcadd($after['floor'], $after['unplaced'], 4), bcadd($before['floor'], $before['unplaced'], 4), 4),
            'কেনা ৪৮টা কেনার ঘরে পৌঁছায়নি — মাল এসেছে, পর্দা বলছে কিছু আসেনি।');

        $this->assertSame('4.0000',
            bcsub(bcadd($after['free'], $after['unplaced_free'], 4), bcadd($before['free'], $before['unplaced_free'], 4), 4),
            'ফ্রি ৪টা ফ্রি-র ঘরে নেই — ঠিক যেটার অভিযোগ মালিক করেছেন।');
    }

    /**
     * এই পণ্যের চারটা সংখ্যা, **পর্দা থেকে** পড়া — সেবা থেকে নয়।
     *
     * @return array{floor: string, unplaced: string, free: string, unplaced_free: string}
     */
    private function stockNumbers(): array
    {
        $html = $this->get(route('inventory.stock.index'))->assertOk()->getContent();
        $headers = $this->headersOf($html);
        $row = $this->stockRowFor($html, $this->product->code);

        $number = fn (string $field) => number_format(
            (float) str_replace(',', '', $this->cell($headers, $row, __('inventory::field.'.$field))),
            4, '.', '',
        );

        return [
            'floor' => $number('floor'),
            'unplaced' => $number('unplaced'),
            'free' => $number('free'),
            'unplaced_free' => $number('unplaced_free'),
        ];
    }

    /**
     * টেবিলের শিরোনামগুলো, যে ক্রমে আঁকা হয়েছে।
     *
     * @return list<string>
     */
    private function headersOf(string $html): array
    {
        preg_match_all('/<th[^>]*>(.*?)<\/th>/s', $html, $cells);

        return array_values(array_filter(array_map(
            static fn (string $c) => trim(preg_replace('/\s+/', ' ', strip_tags($c)) ?? ''),
            $cells[1],
        ), static fn (string $c) => $c !== ''));
    }

    /**
     * শিরোনামের নাম ধরে সারির ঘরটা।
     *
     * @param  list<string>  $headers
     * @param  list<string>  $row
     */
    private function cell(array $headers, array $row, string $label): string
    {
        $at = array_search($label, $headers, true);

        $this->assertNotFalse($at, '"'.$label.'" নামে কোনো কলাম নেই।');

        return $row[$at] ?? '';
    }

    /**
     * ⭐ ছাপা কাগজে ফ্রি আলাদা কলামে।
     *
     * ⓘ A4-তে কলাম, আর সরু থার্মালে নামের নিচে — দুইটার কোনোটাতেই
     * সংখ্যাটা হারায় না। ⚠️ এখানে A4 মাপা হয়, কারণ কলামটা ওখানেই বসে।
     */
    public function test_the_printed_bill_shows_free_in_its_own_column(): void
    {
        $bill = $this->receiveFortyEightAndFourFree();

        $paper = $this->get(route('purchase.print.bill', ['bill' => $bill->id, 'paper' => 'a4']));

        $paper->assertOk();

        /*
         * ⚠️ PDF-এর ভেতরের লেখা এখান থেকে পড়া যায় না, তাই দাবিটা
         * ছাপার **দেহের** উপর — কলামটা আদৌ আঁকা হয় কি না।
         * ⓘ [[print/document-body]] ঘরটা কেবল তখনই আঁকে যখন কাগজে
         * সত্যিই ফ্রি আছে, তাই শিরোনামটা থাকা মানেই সংখ্যাটাও আছে।
         */
        $this->assertSame('application/pdf', $paper->headers->get('content-type'));

        $this->assertStringContainsString(
            __('core.print.free_qty'),
            $this->printedBody([['name' => 'X', 'qty' => '48', 'unit' => 'Carton',
                'rate' => '172.54', 'amount' => '8281.92', 'free' => '4']]),
            'ছাপার কাগজে ফ্রি-র ঘরটাই নেই।');
    }

    /**
     * ⭐ ফ্রি না থাকলে কাগজ অবিকল আগের মতো।
     *
     * ⛔ নাহলে প্রতিটা চালানে একটা শূন্যের কলাম জায়গা নিত, আর সরু
     * রোলে জায়গাটাই সবচেয়ে দামি।
     */
    public function test_a_bill_without_free_goods_prints_no_free_column(): void
    {
        $this->assertStringNotContainsString(
            __('core.print.free_qty'),
            $this->printedBody([['name' => 'X', 'qty' => '48', 'unit' => 'Carton',
                'rate' => '172.54', 'amount' => '8281.92', 'free' => '']]),
            'ফ্রি নেই, তবু কাগজে ফ্রি-র কলাম বসেছে।');
    }

    /**
     * ছাপার দেহটা — A4 কাগজে, দেওয়া সারিগুলো নিয়ে।
     *
     * ⚠️ PDF-এর ভেতরের লেখা এখান থেকে পড়া যায় না, তাই দাবিটা
     * ছাপার **দেহের** উপর — কলামটা আদৌ আঁকা হয় কি না।
     *
     * @param  list<array<string, string>>  $lines
     */
    private function printedBody(array $lines): string
    {
        return view('print.document-body', [
            'doc' => new \App\Core\Engines\Print\PrintableDocument(
                title: 'TEST',
                meta: [],
                lines: $lines,
                totals: [],
            ),
            'paper' => \App\Core\Engines\Print\PaperSize::of('a4'),

            /* ⓘ সরাসরি ভিউ আঁকলে প্রোফাইলটা হাতে দিতে হয় — [[PrintEngine]] ওটা নিজে দেয় */
            'profile' => \App\Core\Engines\Print\PrintProfile::everything(),
        ])->render();
    }

    /**
     * ৪৮ কেনা, ৪ ফ্রি — এক লরিতে।
     */
    private function receiveFortyEightAndFourFree(): \App\Modules\Purchase\Models\PurchaseBill
    {
        return app(DirectPurchaseService::class)->complete(
            $this->documentData(),
            [[
                'product_id' => $this->product->id,
                'qty' => '48',
                'free_qty' => '4',
                'rate' => '172.54',
                'sales_price' => '179.44',
            ]],
        )['bill'];
    }

    /** @return array<string, mixed> */
    private function documentData(): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'FREE-'.fake()->unique()->numberBetween(1000, 9999),
        ];
    }

    /**
     * এই পণ্যের সারির ঘরগুলো — রেন্ডার হওয়া HTML থেকে।
     *
     * ⚠️ কলামের ক্রম ধরে নেওয়া হয় না, কেবল ঘরগুলো তোলা হয়: ক্রম বদলালে
     * পাহারাটা মিথ্যা লাল হত, অথচ সংখ্যাগুলো ঠিকই থাকত।
     *
     * @return list<string>
     */
    private function stockRowFor(string $html, string $code): array
    {
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $rows);

        foreach ($rows[1] as $row) {
            if (! str_contains($row, $code)) {
                continue;
            }

            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);

            return array_map(
                static fn (string $cell) => trim(preg_replace('/\s+/', ' ', strip_tags($cell)) ?? ''),
                $cells[1],
            );
        }

        $this->fail($code.' পণ্যটার সারিই তালিকায় নেই।');
    }

}
