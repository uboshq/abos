<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ফ্রি মালের দাম নেই, তাই খরচের স্তরও নেই — আর সারিটাও ছিল না।
 *
 * ── ⛔ মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"egulor pase free qty diye dio, r last e Total qty Diba free soho
 * protitar pase"* — মূল্যসহ মজুদের প্রতিটা পরিমাণের পাশে তার ফ্রি,
 * আর শেষে হাতে থাকা মোট।
 *
 * ── ⚠️ কেন সংখ্যাটা অন্য টেবিল থেকে আসে ─────────────────────────────
 * [[StockReports::stockValue()]] দাঁড়িয়ে আছে `inv_cost_layers`-এর উপর।
 * ⛔ কিন্তু ঐ টেবিলে ফ্রির কোনো কলামই নেই — স্কিমা দেখে যাচাই করা।
 * ⓘ কারণটা যুক্তিসঙ্গত: ফ্রি মালের দাম নেই, তাই খরচ-স্তরও নেই।
 *
 * ⚠️ তাই পরিমাণটা আসে `inv_stock_movements.free_change` থেকে। ⓘ আর
 * সেজন্যই এই পরীক্ষাটা সংখ্যা ধরে ধরে মেলায়: দুইটা আলাদা টেবিল জোড়া
 * লাগানো হচ্ছে, আর জোড়া ভুল হলে রিপোর্টটা নিখুঁত খুলে **ভুল সংখ্যা**
 * দেখাবে — যা কেউ টের পাবে না, কারণ মেলানোর দ্বিতীয় জায়গা নেই।
 *
 * ── ⭐ আর একটা পুরনো ফাঁক ─────────────────────────────────────────
 * সারি-ছাঁকনিটা আগে কেবল খরচের স্তর দেখত। ⛔ ফলে যে পণ্য **শুধু ফ্রি
 * হিসেবে** এসেছে, তার সারিটাই আসত না — গুদামে মাল আছে, রিপোর্টে
 * পণ্যটাই নেই।
 */
final class TheFreeGoodsHadNoPriceAndSoNoRowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->switchCompany($company->id);

        /*
         * ⛔ খরচের স্তর নেই এমন একটা পণ্য — ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ আগে শুধু `orderBy('id')->first()` নেওয়া হত, আর ওই পণ্যের
         * বীজেই কেনা মাল ছিল। ⓘ ফলে "শুধু ফ্রি হিসেবে আসা পণ্যের
         * সারি আসে" দাবিটা **কখনো ওই অবস্থাটাই দেখেনি** — সারিটা
         * খরচের স্তরের জোরেই আসত।
         *
         * ⛔ প্রমাণ: সারি-ছাঁকনি থেকে ফ্রির শর্তটা তুলে দিলেও
         * পরীক্ষাটা সবুজ থেকেছিল। ⭐ এখন এমন পণ্য বেছে নেওয়া হয়
         * যার একটাও খরচের স্তর নেই — অর্থাৎ ফ্রি ছাড়া তার সারি
         * আসার আর কোনো কারণই নেই।
         */
        /*
         * ⛔ পণ্যটা এখানেই বানানো হয় — ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ আগে ডেমোর প্রথম পণ্যটা নেওয়া হত, আর তার খরচের স্তর
         * ছিল। ⓘ ফলে "শুধু ফ্রি হিসেবে আসা পণ্যের সারি আসে" দাবিটা
         * **কখনো ওই অবস্থাটাই দেখেনি** — সারিটা স্তরের জোরেই আসত।
         *
         * ⛔ প্রমাণ: সারি-ছাঁকনি থেকে ফ্রির শর্তটা তুলে দিলেও
         * পরীক্ষাটা সবুজ থেকেছিল।
         *
         * ⓘ বেছে নেওয়াও যায় না: মেপে দেখা গেছে ডেমোর **প্রতিটা** পণ্যের
         * খরচের স্তর আছে। ⭐ তাই নিজের একটা বানানো হয় — যার একটাও
         * স্তর নেই, অর্থাৎ ফ্রি ছাড়া তার সারি আসার আর কোনো কারণই নেই।
         */
        $this->product = Product::query()->create([
            'code' => 'TST-FREE-ONLY',
            'name_en' => 'Free only product',
            'name_bn' => 'Free only product',
            'unit_id' => Product::query()->value('unit_id'),
            'purchase_price' => '0',
            'sale_price' => '0',
            'reorder_level' => '0',
            'is_active' => true,
        ]);

        /*
         * ⓘ নিজের গুদাম — ডেমোর গুদামে ঐ পণ্যের মজুদ আগে থেকেই বসানো,
         * আর তাতে প্রতিটা সংখ্যা আপেক্ষিক হয়ে যেত।
         */
        $this->store = Warehouse::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'code' => 'TST-FREE',
            'name_en' => 'Free test store',
            'name_bn' => 'Free test store',
            'is_active' => true,
        ]);
    }

    /**
     * ⭐ ফ্রি মাল তার নিজের ঘরে বসে, আর সংখ্যাটা ঠিক।
     */
    public function test_free_goods_land_in_their_own_columns(): void
    {
        $was = $this->snapshot();

        $this->moveFree('12');
        $this->moveFree('-5');

        $row = $this->rowForMyProduct();

        $this->assertNotNull($row, implode("\n", [
            'শুধু ফ্রি মাল আসা পণ্যটার সারিই নেই।',
            '',
            '⛔ সারি-ছাঁকনিটা তাহলে এখনো কেবল খরচের স্তর দেখছে —',
            '   গুদামে মাল আছে, রিপোর্টে পণ্যটাই নেই।',
        ]));

        $this->assertDelta('12', $was, $row, 'in_free', 'আগমন ফ্রি');
        $this->assertDelta('5', $was, $row, 'out_free', 'নির্গমন ফ্রি');
        $this->assertDelta('7', $was, $row, 'closing_free', 'সমাপনী ফ্রি');
    }

    /**
     * ⭐ "মোট পরিমাণ (ফ্রি সহ)" সত্যিই কেনা + ফ্রি।
     *
     * ── ⚠️ কেন আলাদা দাবি ──────────────────────────────
     * অন্য দাবিগুলো কেবল ফ্রির ঘরগুলো মাপে। ⛔ কেউ মোটের সূত্রে
     * ফ্রিটা যোগ করতে ভুলে গেলে ওগুলো সবুজই থাকত, আর মালিক শেষ
     * কলামে কেবল কেনা মালটাই দেখতেন — ঠিক যে সংখ্যাটা তিনি চাননি।
     *
     * ── ⓘ কেনা পরিমাণ এখানে বাড়ানো যায় না ──────────────────
     * প্রথমে `StockService::move(floor:)` দিয়ে ২০ বসানো হয়েছিল,
     * আর সমাপনী পরিমাণ **এক চুলও নড়েনি**। ⛔ কারণ ওই কলটা
     * চলাচলের সারি লেখে, **খরচের স্তর বানায় না** — আর এই
     * রিপোর্টের কেনা পরিমাণ আসে খরচের স্তর থেকেই।
     *
     * ⭐ তাই সূত্রটা সরাসরি মাপা হয়: সারিতে মোট = কেনা + ফ্রি।
     * ⓘ ফ্রি শূন্য নয় বলে দাবিটা সত্যিই কামড়ায় — কেউ মোটে
     * ফ্রি বাদ দিলে দুই পাশ আর মেলে না।
     */
    public function test_the_last_column_counts_the_free_goods_too(): void
    {
        $was = $this->snapshot();

        $this->moveFree('3');

        $row = $this->rowForMyProduct();

        $this->assertNotNull($row, 'পণ্যটার সারি নেই।');

        $this->assertDelta('3', $was, $row, 'closing_free', 'সমাপনী ফ্রি');
        $this->assertDelta('3', $was, $row, 'closing_total', 'মোট (ফ্রি সহ)');

        $sum = bcadd((string) $row['closing_qty'], (string) $row['closing_free'], 4);

        $this->assertSame(0, bccomp((string) $row['closing_total'], $sum, 4), implode('
', [
            'মোট = কেনা + ফ্রি — মিলছে না।',
            '',
            'কেনা '.$row['closing_qty'].' + ফ্রি '.$row['closing_free'].' = '.$sum,
            'রিপোর্ট বলছে '.$row['closing_total'],
        ]));
    }

    /**
     * ⛔ ফ্রি মাল টাকার কলামে **ঢোকে না**।
     *
     * ── ⚠️ কেন এটাই সবচেয়ে জরুরি দাবি ───────────────────────────────
     * ফ্রির দাম শূন্য। ⓘ কেউ যদি ফ্রি পরিমাণটা মূল্যের সূত্রেও জুড়ে
     * দেয়, মজুদের **মোট মূল্য বেড়ে যাবে** — আর সেটা সরাসরি খাতায়
     * মিথ্যা: যে মালের জন্য টাকা দেওয়া হয়নি, তার দাম সম্পদে বসবে।
     *
     * ⭐ পরিমাণ বাড়ে, মূল্য বাড়ে না — এই দুইটা একসাথে দাবি করা হয়।
     */
    public function test_free_goods_never_reach_the_money_columns(): void
    {
        $was = $this->snapshot();

        $this->moveFree('40');

        $row = $this->rowForMyProduct();

        $this->assertNotNull($row, 'পণ্যটার সারি নেই।');

        $this->assertDelta('40', $was, $row, 'closing_free', 'সমাপনী ফ্রি');

        $this->assertDelta('0', $was, $row, 'closing_value', implode("\n", [
            'ফ্রি মাল সমাপনী মূল্যে ঢুকে গেছে।',
            '',
            '⛔ ফ্রির দাম শূন্য — ওটা মূল্যে যোগ হলে সম্পদ বাড়িয়ে দেখানো হয়,',
            '   আর যে মালের জন্য টাকা দেওয়া হয়নি তার দাম খাতায় বসে।',
        ]));

        $this->assertDelta('0', $was, $row, 'in_value', 'আগমন মূল্য');
    }

    /**
     * ⭐ প্রারম্ভিক ফ্রি পরিসরের **আগের** মাল ধরে।
     *
     * ── ⛔ কেন আলাদা দাবি ───────────────────────────────────────────
     * উপরের তিনটাই পরিসরের ভিতরের চলাচল দেখে, আর তাতে তারিখের
     * সীমাটা একবারও পরীক্ষা হয় না। ⚠️ কেউ `$before`-এর বদলে পুরো
     * পরিসর বসিয়ে দিলে সবগুলোই সবুজ থাকত, অথচ প্রারম্ভিক ঘরে
     * আজকের মালও গোনা হত — আর তখন "প্রারম্ভিক + আগমন" দুইবার গুনত।
     */
    public function test_what_came_before_the_range_is_opening_not_incoming(): void
    {
        $was = $this->snapshot();

        $this->moveFree('9', now()->subMonths(2));
        $this->moveFree('4');

        $row = $this->rowForMyProduct();

        $this->assertNotNull($row, 'পণ্যটার সারি নেই।');

        $this->assertDelta('9', $was, $row, 'opening_free', 'প্রারম্ভিক ফ্রি');
        $this->assertDelta('4', $was, $row, 'in_free', 'আগমন ফ্রি');
        $this->assertDelta('13', $was, $row, 'closing_free', 'সমাপনী ফ্রি');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────

    private function moveFree(string $qty, mixed $date = null): void
    {
        $this->be($this->owner);

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->store,
            sourceType: 'test_free',
            sourceId: $this->product->id,
            free: $qty,
            date: $date,
        );
    }

    /**
     * মাল বসানোর আগের সারি — না থাকলে শূন্য।
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return $this->rowForMyProduct() ?? [];
    }

    /**
     * পরম সংখ্যা নয়, **পার্থক্য**।
     *
     * ── ⛔ কেন, ২২ সেপ্টেম্বর ২০২৬ ──────────────────
     * [[StockReports::stockValue()]]-এ গুদামের ভাগ নেই, কারণ
     * `inv_cost_layers`-এ `warehouse_id` নেই। ⓘ তাই নিজের একটা
     * গুদাম বানিয়েও বীজের মজুদ আলাদা করা যায় না — সারিটা
     * সব গুদামের যোগফল।
     *
     * ⚠️ প্রথমে পরম সংখ্যা মিলাতে গিয়ে দাবি লাল হয়েছিল: "২০
     * বসিয়েছি, রিপোর্ট বলছে ১৬০"। ⭐ কোড ঠিক ছিল, অনুমান ভুল।
     *
     * ⓘ পার্থক্য মাপলে বীজে যা-ই থাক দাবিটা সত্য, আর কেউ
     * বীজে মজুদ যোগ করলেই মিথ্যা লাল হয় না।
     *
     * @param  array<string, mixed>  $was
     * @param  array<string, mixed>  $now
     */
    private function assertDelta(string $want, array $was, array $now, string $key, string $what): void
    {
        $before = (string) ($was[$key] ?? '0');
        $after = (string) ($now[$key] ?? '0');
        $moved = bcsub($after, $before, 4);

        $this->assertSame(0, bccomp($want, $moved, 4), implode("\n", [
            $what.' — বদল চাওয়া হয়েছিল '.$want.', হয়েছে '.$moved.'।',
            '',
            'ⓘ আগে '.$before.', পরে '.$after.'।',
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowForMyProduct(): ?array
    {
        $result = app(ReportEngine::class)->run('inventory.stock_value', [
            'from' => now()->subMonth()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);

        foreach ($result->rows as $row) {
            if (str_contains((string) ($row['product_name'] ?? ''), $this->product->code)) {
                return $row;
            }
        }

        return null;
    }
}
