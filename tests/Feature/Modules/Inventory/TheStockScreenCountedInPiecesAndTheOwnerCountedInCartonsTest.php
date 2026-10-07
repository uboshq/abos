<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\Inventory\Support\PackBreakdown;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ পর্দা গুনত পিসে, মালিক গোনেন কার্টনে — ২০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল ──────────────────────────────────────────────────────────
 * মজুদের পাতায় বসত "১৯৩", আর একক লেখা "পিস"। ⓘ মালিকের কথা: কার্টনের
 * মাপ কোম্পানি যখন যেমন খুশি বানায় (*"৬, ৮, ৯, ১২, ২৪, ৪৮, ৭২… আমার
 * কি হাত আছে ভাই"*), আর তিনি মনে মনে **কার্টনেই** গোনেন।
 *
 * ⚠️ "১৯৩ পিস" নিয়ে গুদামে গিয়ে মেলানো যায় না — তাক ধরে গুনতে হলে
 * জানতে হয় ওটা ৮ কার্টন আর ১ পিস।
 *
 * ⭐ সংখ্যাটা সরানো হয়নি, নিচে আরেকটা লাইন যোগ হয়েছে: ভিত্তি এককের
 * সংখ্যাই সব হিসাবের ভিত্তি, আর ওটা না থাকলে যোগফলের সাথে মেলানো যেত না।
 */
final class TheStockScreenCountedInPiecesAndTheOwnerCountedInCartonsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->product = Product::query()->whereNotNull('unit_id')->orderBy('id')->firstOrFail();

        /*
         * ⚠️ ডেমোর পণ্যে আগে থেকেই প্যাক বসানো থাকে, তাই এখানে সেগুলো
         * সরিয়ে নেওয়া হয় — নইলে পরীক্ষাটা ডেমোর বদলের সাথে সাথে নড়ত।
         * ⓘ এককের মাস্টারের সার্বজনীন রূপান্তর (ডজন) থেকেই যায়, আর
         * সেটাই ঠিক: ওটা পণ্যের কথা নয়, সবার কথা।
         */
        ProductUnit::query()->delete();
    }

    /**
     * ⭐ সিঁড়িটা সারি থেকেই আসে, আর ভাঙানিটা তার উপর দাঁড়ায়।
     */
    public function test_a_products_own_carton_size_decides_the_reading(): void
    {
        $this->pack('CTN', '24');

        $ladder = app(PackConversion::class)->laddersFor([$this->product])[$this->product->id];
        $steps = PackBreakdown::split('193', $ladder);

        $this->assertSame('CTN', $steps[0]['unit']->code, 'সবচেয়ে বড় ধাপটা কার্টন হওয়ার কথা।');
        $this->assertSame('8', $steps[0]['qty'], '১৯৩ পিসে ৮ কার্টন (২৪ করে) থাকার কথা।');

        /*
         * ⭐ মাঝখানে এককের মাস্টারের ধাপও (ডজন) থাকতে পারে — সেটা ঠিক,
         * আর সেটাই মালিক গুদামে গুনতে গিয়ে ব্যবহার করেন। ⚠️ তাই প্রতিটা
         * ধাপ পিন করা হয় না; দাবিটা হলো **ভেঙে যোগ করলে আবার ১৯৩**।
         */
        $this->assertSame(0, bccomp($this->rebuild($steps, $ladder), '193', 6),
            'ভেঙে আবার যোগ করলে ১৯৩ আসেনি — কোথাও কিছু হারিয়েছে।');
    }

    /**
     * ⛔ একই সংখ্যা, অন্য পণ্যে অন্য উত্তর — মাপটা পণ্যের, সার্বজনীন নয়।
     *
     * ⚠️ এটাই মালিকের মূল কথা। ⓘ এক জায়গায় ২৪ বসিয়ে রাখলে যে পণ্যের
     * কার্টনে ৭২ পিস, তার গুনতি চিরকাল ভুল পড়া হত।
     */
    public function test_another_product_reads_the_same_number_differently(): void
    {
        $this->pack('CTN', '24');

        $other = Product::query()->whereNotNull('unit_id')->where('id', '!=', $this->product->id)->firstOrFail();
        $this->pack('CTN', '72', $other);

        $ladders = app(PackConversion::class)->laddersFor([$this->product, $other]);

        $mine = PackBreakdown::split('193', $ladders[$this->product->id]);
        $theirs = PackBreakdown::split('193', $ladders[$other->id]);

        $this->assertSame('8', $mine[0]['qty'], 'কার্টনে ২৪ হলে ১৯৩ পিসে ৮ কার্টন।');
        $this->assertSame('2', $theirs[0]['qty'], 'কার্টনে ৭২ হলে ১৯৩ পিসে ২ কার্টন।');

        // দুইটাই একই সংখ্যা থেকে এসেছে, তাই দুইটাই আবার ১৯৩-এ ফেরে
        $this->assertSame(0, bccomp($this->rebuild($mine, $ladders[$this->product->id]), '193', 6));
        $this->assertSame(0, bccomp($this->rebuild($theirs, $ladders[$other->id]), '193', 6));
    }

    /**
     * ⭐ পর্দায় দুইটাই থাকে — ভিত্তি এককের সংখ্যা, আর তার নিচে ভাঙানি।
     */
    public function test_the_stock_screen_shows_both_readings(): void
    {
        $this->pack('CTN', '24');
        $this->shelve('193');

        $html = $this->get(route('inventory.stock.index'))->assertOk()->getContent();

        /*
         * ⓘ ডেমোতে এই পণ্যের কিছু মজুদ আগে থেকেই থাকে, তাই যোগফলটা
         * সারি থেকেই গোনা হয় — সংখ্যাটা হাতে লিখলে ডেমো বদলালেই
         * পরীক্ষাটা নড়ত।
         */
        $total = (int) StockMovement::query()->where('product_id', $this->product->id)->sum('floor_change');

        $this->assertGreaterThan(24, $total, 'এক কার্টনও হয়নি — তাহলে ভাঙানির কিছু নেই।');

        // ⭐ কয় কার্টন, সেটা এখানে আলাদাভাবে গোনা — সেবার উত্তর ধার করা নয়
        $cartons = intdiv($total, 24);

        $this->assertStringContainsString(
            $cartons.' '.$this->unitName('CTN'),
            $html,
            "পাতায় {$cartons} কার্টন দেখানোর কথা ছিল।",
        );
    }

    /** পণ্যের ভিত্তি এককে এতটা তাকে বসানো। */
    private function shelve(string $qty): void
    {
        StockMovement::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => Company::query()->where('code', 'TDEPOT')->firstOrFail()->defaultBranch()?->id,
            'product_id' => $this->product->id,
            'warehouse_id' => Warehouse::query()->orderBy('id')->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'floor_change' => $qty,
            'source_type' => 'opening',
            'source_id' => $this->product->id,
        ]);
    }

    private function unitName(string $code): string
    {
        return Unit::query()->where('company_id', CompanyContext::id())->where('code', $code)->firstOrFail()->name();
    }

    /** ভাগগুলো আবার যোগ করলে কত — সিঁড়ির factor ধরে। */
    private function rebuild(array $steps, array $ladder): string
    {
        $factors = [];

        foreach ($ladder as $one) {
            $factors[$one['unit']->id] = $one['factor'];
        }

        $sum = '0';

        foreach ($steps as $step) {
            $sum = bcadd($sum, bcmul($step['qty'], $factors[$step['unit']->id], 6), 6);
        }

        return $sum;
    }

    private function pack(string $code, string $factor, ?Product $product = null): void
    {
        $product = $product ?? $this->product;

        $unit = Unit::query()->firstOrCreate(
            ['company_id' => CompanyContext::id(), 'code' => $code],
            ['name_en' => $code, 'factor' => 1, 'is_active' => true],
        );

        ProductUnit::query()->updateOrCreate(
            ['product_id' => $product->id, 'unit_id' => $unit->id],
            [
                'company_id' => CompanyContext::id(),
                'factor' => $factor,
                'is_active' => true,
            ],
        );
    }
}
