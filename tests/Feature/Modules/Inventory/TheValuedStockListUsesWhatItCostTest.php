<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * মজুদ মূল্যসহ — টাকাটা **যা খরচ হয়েছে** তা, আজকের দাম নয়।
 *
 * ── ⭐ কেন এই পাহারাটা, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * মালিক চেয়েছেন প্রতিটা ঘরে পরিমাণ আর মূল্য দুইটাই
 * ([[StockReports::stockValue]])। ⓘ রিপোর্টটা টাকা তোলে খরচের স্তর
 * থেকে (`inv_cost_layers` / `inv_cost_layer_uses`)।
 *
 * ── ⛔ যে "সরলীকরণ" থেকে এই ফাইলটা পাহারা দেয় ───────────────────────
 * পরের যে কেউ তাকিয়ে ভাবতে পারেন *"চারটা উপ-কোয়েরি কেন, পরিমাণ ×
 * পণ্যের ক্রয়মূল্য করলেই তো হয়"* — আর সেটা **প্রায়** ঠিক উত্তর দেয়,
 * তাই ধরা পড়ে না।
 *
 * ⚠️ ভুলটা তখনই দেখা যায় যখন একই পণ্য দুই দামে কেনা হয়েছে। তাই এই
 * পরীক্ষার পণ্যটা **দুইবার, দুই দামে** কেনা, আর দুইটা দামের কোনোটাই
 * পণ্যের নিজের `purchase_price` নয় — যাতে সরলীকরণটা করলে সংখ্যাটা
 * মিলতেই না পারে।
 */
final class TheValuedStockListUsesWhatItCostTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    /*
     * ⓘ তিনটা দামই আলাদা, আর সেটাই পরীক্ষার পুরো কৌশল:
     * ৮০ আর ১০০-তে কেনা, অথচ পণ্যের ঘরে লেখা ৯৫। যে কোড পণ্যের
     * ঘরটা পড়বে সে ৯৫ দিয়ে গুণ করবে, আর কোনো যোগফলই মিলবে না।
     */
    private const FIRST_COST = '80';

    private const SECOND_COST = '100';

    private const PRICE_ON_THE_PRODUCT = '95';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->forceFill(['purchase_price' => self::PRICE_ON_THE_PRODUCT])->save();

        /*
         * ⛔ ডেমোর স্তরগুলো আগে সরানো হয়, আর কারণটা মাপা।
         *
         * ⚠️ প্রথম চালে সমাপনী এল ১৭৬, অথচ আমার বসানো মাল ১৬ —
         * [[DemoSeeder]] নিজেই ছয়টা পণ্যে স্তর বানায় (PRD-0001-এ ১৬০)।
         * ⓘ অর্থাৎ দাবিটা আমার অঙ্ক মাপছিল না, আমার অঙ্ক **যোগ**
         * ডেমোর অঙ্ক মাপছিল।
         *
         * ⭐ ছাঁকনি দিয়ে নিজের পণ্যটা আলাদা করা যেত, কিন্তু তাতে
         * "কিছুই ঘটেনি এমন পণ্যের সারি আসে না" দাবিটা মাপাই যেত না।
         * তাই জগৎটা খালি করে নিজের হাতে গড়া — এই ফাইলের প্রতিটা
         * সংখ্যা তখন এখানেই লেখা, আর সিডার বদলালেও নড়বে না।
         */
        DB::table('inv_cost_layer_uses')->delete();
        DB::table('inv_cost_layers')->delete();

        /*
         * ⓘ স্তরগুলো সরাসরি বসানো হয়, ক্রয় বিল বানিয়ে নয়। ⚠️ বিল
         * দিয়ে গেলে এই দাবিটা ক্রয় মডিউলের অর্ধেক নিয়মের উপরও
         * দাঁড়াত, আর একদিন লাল হলে বোঝা যেত না ভুলটা কার।
         */
        DB::table('inv_cost_layers')->insert([
            $this->layer('2026-03-10', '10', self::FIRST_COST),
            $this->layer('2026-03-20', '10', self::SECOND_COST),
        ]);
    }

    /**
     * ⭐ আসল দাবি: নির্গমনের টাকা FIFO ধরে, পণ্যের দাম ধরে নয়।
     *
     * ⓘ প্রথম স্তর থেকে ৬টা বেচলে খরচ ৬ × ৮০ = ৪৮০। ⛔ সরলীকরণ করলে
     * হত ৬ × ৯৫ = ৫৭০ — ৯০ টাকার ফারাক, আর সেটাই লাভ-লোকসানে যেত।
     */
    public function test_the_out_value_is_what_the_goods_cost_not_todays_price(): void
    {
        DB::table('inv_cost_layer_uses')->insert([$this->use('2026-04-05', '6', self::FIRST_COST)]);

        $row = $this->row('2026-04-01', '2026-04-30');

        $this->assertSame('6.0000', $this->qty($row->out_qty));
        $this->assertSame('480.0000', $this->qty($row->out_value), implode("\n", [
            '⛔ নির্গমনের মূল্য ৪৮০ হওয়ার কথা — ৬টা মাল, প্রতিটা ৮০ টাকায় কেনা।',
            '',
            '⚠️ ৫৭০ পেলে কেউ পণ্যের `purchase_price` (৯৫) দিয়ে গুণ করছে,',
            'আর তখন ছয় মাস আগের মাল আজকের দামে মাপা হচ্ছে।',
        ]));
    }

    /**
     * ⭐ প্রারম্ভিক = শুরুর তারিখের **আগের** সব, আর একদিনও দুইবার নয়।
     *
     * ⛔ সীমানার ভুলটা এখানেই লুকায়: `from`-এর দিনটা যদি প্রারম্ভিকেও
     * ধরা হয় আর আগমনেও, তবে ঐ দিনের মাল দুইবার গোনা হয় আর সমাপনী
     * বেশি দেখায়।
     */
    public function test_the_opening_stops_the_day_before_the_range_starts(): void
    {
        /* ⓘ ২০ মার্চের স্তরটা পরিসরের ভিতরে, ১০ মার্চেরটা আগে। */
        $row = $this->row('2026-03-20', '2026-03-31');

        $this->assertSame('10.0000', $this->qty($row->opening_qty), 'প্রারম্ভিকে ১০টা থাকার কথা — ১০ মার্চের স্তরটা।');
        $this->assertSame('800.0000', $this->qty($row->opening_value), 'প্রারম্ভিক মূল্য ১০ × ৮০ = ৮০০।');

        $this->assertSame('10.0000', $this->qty($row->in_qty), 'আগমনে ১০টা — ২০ মার্চের স্তরটা, আর কেবল ওটাই।');
        $this->assertSame('1000.0000', $this->qty($row->in_value), 'আগমন মূল্য ১০ × ১০০ = ১০০০।');
    }

    /**
     * ⭐ সমীকরণটা সারিতে সবসময় মেলে: প্রারম্ভিক + আগমন − নির্গমন।
     *
     * ⓘ সমাপনী আলাদা করে খোঁজা হয় না, গোনা হয় — তাই দুইটা আলাদা
     * হওয়ার উপায় নেই। ⚠️ কেউ যদি একদিন সমাপনীকে অন্য উৎস থেকে আনে,
     * এই দাবিটাই প্রথম টের পাবে।
     */
    public function test_closing_is_opening_plus_in_minus_out(): void
    {
        DB::table('inv_cost_layer_uses')->insert([$this->use('2026-03-25', '4', self::FIRST_COST)]);

        $row = $this->row('2026-01-01', '2026-12-31');

        $this->assertSame(
            bcsub(bcadd($this->qty($row->opening_qty), $this->qty($row->in_qty), 4), $this->qty($row->out_qty), 4),
            $this->qty($row->closing_qty),
            'সমাপনী পরিমাণ সমীকরণের সাথে মিলছে না।',
        );

        $this->assertSame(
            bcsub(bcadd($this->qty($row->opening_value), $this->qty($row->in_value), 4), $this->qty($row->out_value), 4),
            $this->qty($row->closing_value),
            'সমাপনী মূল্য সমীকরণের সাথে মিলছে না।',
        );

        /* ⓘ ২০টা কেনা, ৪টা গেছে — ১৬টা, আর টাকায় ১৮০০ − ৩২০ = ১৪৮০। */
        $this->assertSame('16.0000', $this->qty($row->closing_qty));
        $this->assertSame('1480.0000', $this->qty($row->closing_value));
    }

    /**
     * ⛔ যে পণ্যের কিছুই ঘটেনি তার সারি আসে না।
     *
     * ⚠️ নাহলে গোটা পণ্য-তালিকা শূন্যের সারি হয়ে ছাপা হত, আর আসল
     * সারিগুলো তার ভিতরে হারাত — ঠিক যে কারণে ব্যাচের রিপোর্টেও শেষ
     * হয়ে যাওয়া লট বাদ দেওয়া হয়।
     */
    public function test_a_product_that_never_moved_is_not_a_row(): void
    {
        $rows = $this->rows('2026-01-01', '2026-12-31');

        $this->assertCount(1, $rows, 'কেবল একটাই পণ্যের স্তর বসানো হয়েছে, তবু একাধিক সারি এসেছে।');
        $this->assertGreaterThan(1, Product::query()->count(), 'ডেমোতে একটাই পণ্য — দাবিটা তখন কিছুই প্রমাণ করে না।');
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের দাবিগুলো সংখ্যা মেলায়; এটা মেলায় **খোঁজাটা কাজ করছে
     * কি না**। ⚠️ `rows()` চিরকাল খালি ফেরালে `test_a_product_that_
     * never_moved` দিব্যি সবুজ থাকত, আর বাকিগুলো `firstOrFail`-এ
     * ভাঙত — অর্থাৎ ভুল বার্তা নিয়ে।
     */
    public function test_the_report_is_actually_registered(): void
    {
        $definition = app(ReportEngine::class)->get('inventory.stock_value');

        $this->assertSame('inventory::menu.stock_value', $definition->title);

        $keys = array_column($definition->columns, 'key');

        foreach (['opening_qty', 'opening_value', 'in_qty', 'in_value',
            'out_qty', 'out_value', 'closing_qty', 'closing_value'] as $needed) {
            $this->assertContains($needed, $keys, "কলাম '{$needed}' রিপোর্টে নেই — মালিক প্রতিটার পরিমাণ ও মূল্য দুইটাই চেয়েছেন।");
        }
    }

    /**
     * ⓘ দশমিকের রূপটা ড্রাইভারভেদে আলাদা (`480.00000000` বনাম `480`),
     * তাই তুলনার আগে একটা রূপে আনা হয় — নইলে দাবিটা সংখ্যার কথা না
     * বলে স্ট্রিং-এর কথা বলত।
     */
    private function qty(mixed $value): string
    {
        return bcadd((string) $value, '0', 4);
    }

    private function row(string $from, string $to): object
    {
        return $this->rows($from, $to)[0];
    }

    /** @return list<object> */
    private function rows(string $from, string $to): array
    {
        $definition = app(ReportEngine::class)->get('inventory.stock_value');

        return array_values(($definition->query)([
            'company_id' => $this->company->id,
            'branch_id' => null,
            'from' => $from,
            'to' => $to,
        ])->get()->all());
    }

    /** @return array<string, mixed> */
    private function layer(string $date, string $qty, string $cost): array
    {
        return [
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'source_type' => 'purchase_bill',
            'source_id' => 1,
            'document_no' => 'TEST-'.$date,
            'trx_date' => $date,
            'qty_in' => $qty,
            'qty_remaining' => $qty,
            'unit_cost' => $cost,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function use(string $date, string $qty, string $cost): array
    {
        return [
            'company_id' => $this->company->id,
            'cost_layer_id' => DB::table('inv_cost_layers')->orderBy('id')->value('id'),
            'product_id' => $this->product->id,
            'source_type' => 'sales_invoice',
            'source_id' => 1,
            'document_no' => 'TEST-OUT-'.$date,
            'trx_date' => $date,
            'qty' => $qty,
            'unit_cost' => $cost,
            'amount' => bcmul($qty, $cost, 4),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
