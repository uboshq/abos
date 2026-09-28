<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একটা বিল এল, আর বসানোর পর্দায় দুইটা কাগজ হয়ে বসল।
 *
 * ── ⓘ মালিকের প্রশ্ন, ২২ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"একটা বিল ক্রয় হলো, কিন্তু দুটো ভাগ কেন?"* — একই বিল (PBL-0004)
 * পর্দায় দুইটা কার্ড: একটার আইডি `purchase_bill-free:8`, অন্যটার
 * `purchase_bill-8`।
 *
 * ── ⚠️ কেন হত ───────────────────────────────────────────────────────
 * ফ্রি মাল নিজের উৎস-নামে চলে (`…:free`), আর কারণটা ঠিকই আছে: ফ্রি
 * কার্টনের ক্রয়মূল্য নেই, তাই সে আলাদা ভাণ্ডারে ঢোকে আর বাতিলের সময়
 * আলাদা করে চেনা যায়।
 *
 * ⛔ কিন্তু ওটা **হিসাবের ভাগ**, আর বসানোর পর্দা **কাজের পর্দা**।
 * গুদামের লোকের কাছে একটাই লরি, একটাই কাগজ — তাঁকে একই বিল দুইবার
 * খুঁজে বের করতে হত। ⚠️ আর দুইটা কার্ডেই অর্ধেক ঘর মৃত: ফ্রি কার্ডে
 * টাকার ঘরে `0.0000`, টাকার কার্ডে ফ্রির ঘরে `—`।
 */
final class OneBillArrivedAsTwoPapersToPutAwayTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /**
     * ⭐ টাকার মাল আর ফ্রি মাল — একই বিল, একটাই কার্ড।
     */
    public function test_the_paid_and_the_free_goods_share_one_paper(): void
    {
        $this->goodsArrived();

        $papers = $this->papersOnScreen();

        $this->assertCount(
            1,
            $papers,
            'একই বিল এখনো '.count($papers).'টা কার্ড হয়ে বসছে।',
        );
    }

    /**
     * ⭐ মাথা আর সারির ঘর সমান — মালিকের ছবি, ২৮ সেপ্টেম্বর ২০২৬: *"eto elo melo keno"*।
     * ⛔ মাথার তাক-ঘরগুলো শর্তে আসত, সারির ঘর সবসময় — কলাম তিন ঘর সরে যেত।
     */
    public function test_every_row_has_as_many_cells_as_the_header(): void
    {
        $this->goodsArrived();

        $html = $this->get(route('inventory.stock.placement'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<table class="ui-list w-full">(.*?)<\/table>/su',
            substr($html, (int) strpos($html, 'PBL-4242')), $table), 'প্রস্তুতিটাই ভুল — কাগজের সারণি নেই।');

        preg_match('/<thead>(.*?)<\/thead>/su', $table[1], $head);

        /* ⛔ মাথার কোনো ঘর শর্তের `<template>`-এ নয় — ব্রাউজারে ওটা শর্ত মিথ্যা হলে আঁকা
           হয় না, আর তখন মাথা ছোট, সারি বড় (আগের ভুলটা ঠিক এই) */
        $this->assertDoesNotMatchRegularExpression('/<template[^>]*>\s*<th/u', $head[1],
            '⛔ মাথার ঘর শর্তে আঁকা হয় — তাক না থাকলে কলাম সরে যায়।');

        $columns = substr_count($head[1], '<th');

        preg_match_all('/<tr class="border-t[^"]*"(.*?)<\/tr>/su', $table[1], $rows);
        $this->assertNotEmpty($rows[1], 'প্রস্তুতিটাই ভুল — কোনো সারি নেই।');

        foreach ($rows[1] as $row) {
            $this->assertSame($columns, substr_count($row, '<td'),
                "⛔ মাথায় {$columns}টা ঘর, সারিতে ".substr_count($row, '<td')."টা — কলাম সরে যায়।");
        }
    }

    /**
     * ⛔ আর কার্ডটায় দুই রকম মালই থাকে — একটা বাদ পড়ে না।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * ⓘ উপরেরটা কেবল গোনে। ⛔ দল বাঁধার সময় ফ্রি সারিটা **চাপা পড়ে
     * গেলেও** ওটা সবুজ থাকত — একটাই কার্ড, কিন্তু ফ্রি মাল উধাও, আর
     * সেটা আগের অবস্থার চেয়েও খারাপ।
     */
    public function test_neither_kind_of_goods_goes_missing(): void
    {
        $this->goodsArrived();

        $paper = $this->papersOnScreen()[0];

        $waiting = collect($paper['lines'])->sum(fn (array $l) => (float) $l['waiting']);
        $free = collect($paper['lines'])->sum(fn (array $l) => (float) $l['waiting_free']);

        $this->assertSame(10.0, $waiting, 'টাকার মালটা কার্ডে নেই।');
        $this->assertSame(2.0, $free, 'ফ্রি মালটা কার্ডে নেই।');
    }

    /**
     * ⭐ আর ফ্রি সারিটা নিজের উৎস-নামটা সাথে নিয়েই চলে।
     *
     * ── ⛔ কেন এটাই সবচেয়ে জরুরি দাবি ──────────────────────────────
     * বসানোর সারিটা **যে উৎসে এসেছিল ঠিক সেই উৎসেই** লিখতে হয়।
     * ⚠️ কার্ড এক করতে গিয়ে সারিটাকেও মূল কাগজের নামে পাঠালে আসা আর
     * বসানো দুইটা আলাদা দলে পড়ত, যোগফল কাটাকাটি হত না, আর কাগজটা
     * তালিকা থেকে **কোনোদিন সরত না** — মাল বসে যাওয়ার পরেও।
     *
     * ⓘ ঐ ভুলটা এই রিপোতে একবার হয়েই গেছে (৪ সেপ্টেম্বর ২০২৬), আর
     * কোড পড়ে ধরা পড়েনি — ব্রাউজারে বসিয়ে তারপর পাতা খুলে ধরা পড়ে।
     */
    public function test_the_free_line_keeps_its_own_source(): void
    {
        $this->goodsArrived();

        /* ⓘ এখন এক সারি — ফ্রি মালের উৎস `free_source_type`-এ ([[paidAndFreeOnOneRow()]]) */
        $sources = collect($this->papersOnScreen()[0]['lines'])
            ->flatMap(fn (array $l) => array_filter([$l['source_type'], $l['free_source_type'] ?? null]))
            ->unique()
            ->values();

        $this->assertTrue(
            $sources->contains(fn (string $s) => str_contains($s, ':free')),
            'ফ্রি সারিটা নিজের উৎস-নাম হারিয়েছে — বসানোর পর কাগজটা আর সরবে না।',
        );

        $this->assertTrue(
            $sources->contains(fn (string $s) => ! str_contains($s, ':free')),
            'টাকার সারিটার উৎস-নাম হারিয়েছে।',
        );
    }

    /**
     * ⭐ একই পণ্যের টাকার মাল আর ফ্রি মাল এক সারিতে — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর
     * ২০২৬: *"Free alada hoye kothay giyeche … dekte somossa hoy"*।
     */
    public function test_the_paid_and_the_free_goods_of_one_product_share_one_row(): void
    {
        $this->goodsArrived();

        $lines = $this->papersOnScreen()[0]['lines'];

        $this->assertCount(1, $lines, '⛔ একই পণ্য এখনো '.count($lines).'টা সারিতে।');
        $this->assertSame(10.0, (float) $lines[0]['waiting']);
        $this->assertSame(2.0, (float) $lines[0]['waiting_free']);
    }

    /**
     * ⛔ এক সারিতে বসালেও দুই উৎসই কাটে — কাগজটা তালিকা থেকে সরে যায়।
     * ⓘ ফ্রি মাল নিজের উৎসে না বসলে কাগজটা চিরকাল "বসানোর অপেক্ষায়" থাকত।
     */
    public function test_placing_the_joined_row_clears_both_kinds_and_the_paper_leaves(): void
    {
        $this->goodsArrived();

        $line = $this->papersOnScreen()[0]['lines'][0];

        $this->post(route('inventory.stock.placement.store'), [
            'lines' => [[
                'product_id' => $line['product_id'],
                'warehouse_id' => $line['warehouse_id'],
                'batch_id' => $line['batch_id'],
                'source_type' => $line['source_type'],
                'free_source_type' => $line['free_source_type'],
                'source_id' => $line['source_id'],
                'qty' => '10',
                'free_qty' => '2',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame([], $this->papersOnScreen(), '⛔ সব বসানোর পরেও কাগজটা তালিকায় রয়ে গেছে।');
    }

    /**
     * ⭐ এক পণ্য, দুই জায়গা — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬। ⓘ উপ-সারি
     * (`lines[0_0]`) "এই সারিটা বসাও"-তেও মূল সারির সাথে যায়, আর দুই চাপ মিলে
     * কাগজটা শেষ হয়।
     */
    public function test_one_product_is_split_across_two_places_in_one_press(): void
    {
        $this->goodsArrived();

        $line = $this->papersOnScreen()[0]['lines'][0];
        $base = [
            'product_id' => $line['product_id'],
            'warehouse_id' => $line['warehouse_id'],
            'batch_id' => $line['batch_id'],
            'source_type' => $line['source_type'],
            'free_source_type' => $line['free_source_type'],
            'source_id' => $line['source_id'],
        ];

        $this->post(route('inventory.stock.placement.store'), [
            'only' => '0',
            'lines' => [
                '0' => $base + ['qty' => '6', 'free_qty' => '2'],
                '0_0' => $base + ['qty' => '4', 'free_qty' => '0'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame([], $this->papersOnScreen(), '⛔ দুই জায়গায় সব বসানোর পরেও কাগজটা তালিকায়।');
    }

    /** ⛔ দুই জায়গা মিলিয়ে বেশি হলে সার্ভার নেয় না — আর কিছুই বসে না। */
    public function test_splits_that_add_up_to_more_than_waiting_are_refused(): void
    {
        $this->goodsArrived();

        $line = $this->papersOnScreen()[0]['lines'][0];
        $base = [
            'product_id' => $line['product_id'],
            'warehouse_id' => $line['warehouse_id'],
            'batch_id' => $line['batch_id'],
            'source_type' => $line['source_type'],
            'source_id' => $line['source_id'],
        ];

        $this->post(route('inventory.stock.placement.store'), [
            'lines' => [
                '0' => $base + ['qty' => '7'],
                '0_0' => $base + ['qty' => '4'],
            ],
        ])->assertSessionHasErrors();

        $this->assertSame(10.0, (float) $this->papersOnScreen()[0]['lines'][0]['waiting'],
            '⛔ বেশি চাওয়া হলেও কিছু বসে গেছে — লেনদেন উল্টায়নি।');
    }

    /**
     * ⭐ পরিমাণে অকারণ দশমিক নয় — মালিকের ছবি, ২৮ সেপ্টেম্বর ২০২৬: *"Qnt. te dosomik dewar
     * dorkar nai … zodi thake tokon ze product e thakbe shudu sei product e dekhabe"*।
     *
     * ⚠️ দুই দিক একসাথে: পুরো কার্টন "10" (না "10.0000"), আর ভাঙা কার্টন "2.5" — ⛔ শূন্য
     * কাটতে গিয়ে ভাঙা অংশটাও কেটে ফেললে গুদামের লোক আড়াইয়ের জায়গায় দুই বসাতেন।
     */
    public function test_whole_cartons_show_no_decimals_and_a_broken_one_keeps_its_fraction(): void
    {
        $this->goodsArrived();

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'purchase_bill:free',
            sourceId: 4242,
            documentNo: 'PBL-4242',
            unplacedFree: '0.5',
        );

        $line = $this->papersOnScreen()[0]['lines'][0];

        $this->assertSame('10', $line['waiting'], '⛔ পুরো কার্টনে দশমিক দেখায়।');
        $this->assertSame('2.5', $line['waiting_free'], '⛔ ভাঙা কার্টনের দশমিক হারিয়েছে বা শূন্য ঝুলছে।');

        $html = $this->get(route('inventory.stock.placement'))->getContent();
        $this->assertStringNotContainsString('value="10.0000"', $html);
    }

    /** একই কাগজে দশটা টাকার মাল আর দুইটা ফ্রি — দুই উৎসে, যেভাবে ক্রয় লেখে। */
    private function goodsArrived(): void
    {
        $stock = app(StockService::class);

        $stock->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'purchase_bill',
            sourceId: 4242,
            documentNo: 'PBL-4242',
            unplaced: '10',
        );

        $stock->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'purchase_bill:free',
            sourceId: 4242,
            documentNo: 'PBL-4242',
            unplacedFree: '2',
        );
    }

    /**
     * পর্দার কাগজগুলো — কেবল আমাদের বসানো বিলটা।
     *
     * ⓘ ডেমোতে আগে থেকেই কিছু কাগজ থাকতে পারে, তাই গোনার আগে ছাঁকা
     * হয় — নাহলে দাবিটা ডেমোর অবস্থা মাপত, সারাইটা নয়।
     *
     * @return list<array<string, mixed>>
     */
    private function papersOnScreen(): array
    {
        $papers = $this->get(route('inventory.stock.placement'))
            ->assertOk()
            ->viewData('papers');

        return collect($papers)
            ->filter(fn (array $p) => (int) $p['source_id'] === 4242)
            ->values()
            ->all();
    }
}
