<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ প্রতিটা পণ্য নিজের প্যাক ধরে গোনে — ধাপ ৩, ১৯ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ দুই দিকের দাবি। এক: পণ্যের টেবিলে সারি থাকলে সেটাই জেতে (সাবানে
 * কার্টন ২৪, বিস্কুটে ৪৮)। দুই: টেবিল খালি হলে উত্তর **আগের মতোই** —
 * এটা না থাকলে ব্যাকফিলের পরদিন পুরনো পণ্যের প্রতিটা ফর্ম অন্য সংখ্যা
 * দিত। পুরনো `PackConversionTest`-ও এই কারণেই না বদলে সবুজ থাকতে হবে।
 */
final class EachProductConvertsByItsOwnPackTest extends TestCase
{
    use RefreshDatabase;

    private Unit $piece;

    private Unit $carton;

    private Product $soap;

    private Product $biscuit;

    private PackConversion $packs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->piece = Unit::query()->where('code', 'PCS')->firstOrFail();
        // লাইভের মতো: কার্টন নিজেই গোড়া, factor ১, কারো সাথে জোড়া নয়
        $this->carton = Unit::query()->where('code', 'CTN')->firstOrFail();

        $this->soap = $this->product('TSOAP', ['CTN' => '24']);
        $this->biscuit = $this->product('TBISC', ['CTN' => '48']);

        $this->packs = app(PackConversion::class);
    }

    /** @param  array<string, string>  $packs  এককের কোড → কত পিস */
    private function product(string $code, array $packs): Product
    {
        $product = Product::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => $code,
            'unit_id' => $this->piece->id,
            'is_active' => true,
        ]);

        $this->pack($product, $this->piece, '1');

        foreach ($packs as $unitCode => $factor) {
            $this->pack($product, Unit::query()->where('code', $unitCode)->firstOrFail(), $factor);
        }

        return $product;
    }

    private function pack(Product $product, Unit $unit, string $factor, bool $active = true): ProductUnit
    {
        return ProductUnit::query()->create([
            'company_id' => CompanyContext::id(),
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'factor' => $factor,
            'is_active' => $active,
        ]);
    }

    /**
     * ⭐ একই "কার্টন", দুই পণ্যে দুই মাপ — মজুদে, দরে, দুটোতেই।
     */
    public function test_one_carton_counts_differently_for_each_product(): void
    {
        $this->assertSame('48.000000', $this->packs->toStockQty($this->soap, '2', $this->carton->id));
        $this->assertSame('96.000000', $this->packs->toStockQty($this->biscuit, '2', $this->carton->id));

        // ১ কার্টন সাবান ৯৬০ টাকা → পিস ৪০
        $this->assertSame('40.000000', $this->packs->toStockRate($this->soap, '960', $this->carton->id));
    }

    /**
     * ⭐ পণ্যের টেবিল খালি হলে আগের নিয়মই — এককের মাস্টার।
     *
     * ⓘ ডজন মাস্টারে পিসের সাথে জোড়া (১২), আর সাবানের টেবিলে ডজন নেই।
     */
    public function test_a_unit_not_in_the_table_falls_back_to_the_master(): void
    {
        $dozen = Unit::query()->where('code', 'DOZ')->firstOrFail();
        $dozen->forceFill(['base_unit_id' => $this->piece->id, 'factor' => '12'])->save();

        $this->assertSame('12.000000', app(PackConversion::class)->factorFor($this->soap, $dozen->id));
    }

    /**
     * ⭐ একই একক দুই জায়গায় — পণ্যের প্যাক জেতে, ড্রপডাউনেও, মজুদেও।
     */
    public function test_the_products_own_pack_beats_the_master(): void
    {
        $dozen = Unit::query()->where('code', 'DOZ')->firstOrFail();
        $dozen->forceFill(['base_unit_id' => $this->piece->id, 'factor' => '12'])->save();

        /*
         * এই পণ্যের "ডজন" আসলে ৩০টা — বিক্রেতার নিজের প্যাক।
         *
         * ⚠️ ৩০, কারণ সেটা কার্টনের (২৪) ওপরে পড়ে আর মাস্টারের ১২ পড়ে
         * নিচে — তাই ড্রপডাউনের **ক্রমই** বলে দেয় কোন সংখ্যা জিতেছে।
         * ⓘ আগে ১০ ছিল, আর ১০ আর ১২ দুটোই কার্টন আর পিসের মাঝে বসে —
         * মাস্টার জিতলেও ক্রম একই থাকত, আর মিউটেশন সবুজ থেকে গিয়েছিল।
         */
        $this->pack($this->soap, $dozen, '30');
        $packs = app(PackConversion::class);

        $this->assertSame('30.000000', $packs->factorFor($this->soap, $dozen->id));

        $ids = array_column($packs->optionsFor([$this->soap])[$this->soap->id], 'id');
        $this->assertSame([$dozen->id, $this->carton->id, $this->piece->id], $ids, 'ড্রপডাউনে পণ্যের প্যাক জেতেনি');
        $this->assertSame(1, count(array_keys($ids, $dozen->id)), 'ডজন দুইবার এসেছে।');
    }

    /**
     * ⭐ ড্রপডাউন পণ্য ধরে — সাবানে কার্টন আছে, প্যাক ছাড়া পণ্যে নেই।
     */
    public function test_the_pick_list_is_per_product_biggest_first(): void
    {
        $plain = Product::query()->where('unit_id', $this->piece->id)
            ->whereNotIn('id', [$this->soap->id, $this->biscuit->id])->orderBy('id')->firstOrFail();

        $options = $this->packs->optionsFor([$this->soap, $this->biscuit, $plain]);

        // ⓘ ডজন মাঝখানে: ধাপ ২-এর পর থেকে সে সব পিসের পণ্যে সার্বজনীন (১২)
        $dozen = Unit::query()->where('code', 'DOZ')->value('id');

        $this->assertSame([$this->carton->id, $dozen, $this->piece->id], array_column($options[$this->soap->id], 'id'));
        $this->assertSame([$this->carton->id, $dozen, $this->piece->id], array_column($options[$this->biscuit->id], 'id'));
        $this->assertNotContains($this->carton->id, array_column($options[$plain->id] ?? [], 'id'),
            'প্যাক ছাড়া পণ্যে কার্টন এসেছে — সেটার মাপ কেউ বলেনি।');
    }

    /**
     * ⭐ অনেক পণ্যে কোয়েরি বাড়ে না — এককের তালিকা একবার, প্যাক একবার।
     */
    public function test_the_pick_list_does_not_query_per_product(): void
    {
        $many = Product::query()->get();

        DB::enableQueryLog();
        $this->packs->optionsFor($many);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $count, "optionsFor() {$count}টা কোয়েরি চালিয়েছে — পণ্যপ্রতি একটা?");
        $this->assertGreaterThan(3, $many->count(), 'পরীক্ষা অন্ধ: পণ্যই কম।');
    }

    /**
     * ⛔ নিষ্ক্রিয় প্যাক গোনে না — আর তখন কার্টনের কোনো মাপই জানা নেই।
     */
    public function test_an_inactive_pack_is_ignored(): void
    {
        ProductUnit::query()->where('product_id', $this->soap->id)
            ->where('unit_id', $this->carton->id)->update(['is_active' => false]);

        $packs = app(PackConversion::class);

        $this->assertNotContains($this->carton->id, array_column($packs->optionsFor([$this->soap])[$this->soap->id] ?? [], 'id'),
            'নিষ্ক্রিয় কার্টন ড্রপডাউনে এসেছে।');

        $this->expectException(ValidationException::class);
        $packs->toStockQty($this->soap, '1', $this->carton->id);
    }

    /**
     * ⛔ পিস ভাঙে না — প্যাকের মাপেও আধখানা পিস আটকায়।
     */
    public function test_a_pack_that_leaves_half_a_piece_is_refused(): void
    {
        $this->assertSame('12.000000', $this->packs->toStockQty($this->soap, '0.5', $this->carton->id));

        $this->expectException(ValidationException::class);
        $this->packs->toStockQty($this->soap, '0.1', $this->carton->id);
    }

    /**
     * ⭐ পণ্য সিঁড়ির মাঝখানে গোনা হলেও (পাতায়) প্যাক আর সার্বজনীন একক
     * একই মাপে মেলে।
     *
     * ⓘ সিঁড়ি: ১ বাক্স = ১০ পাতা, ১ পাতা = ১০ পিস — মাস্টারে। পণ্যটা পাতায়
     * গোনা, আর তার নিজের কার্টন = ৫ পাতা। ⚠️ মাস্টারের এককগুলো পণ্যের
     * base (পাতা) দিয়ে ভাগ না করলে বাক্স ১০০, পাতা ১০ হয়ে যেত — পিসের
     * হিসাবে — আর কার্টনের ৫ (পাতার হিসাবে) তাদের সাথে মিশে ভুল ক্রমে বসত।
     */
    public function test_a_product_counted_mid_ladder_mixes_packs_and_master_on_one_scale(): void
    {
        $strip = Unit::query()->create(['company_id' => CompanyContext::id(), 'code' => 'TPATA', 'name_en' => 'Strip',
            'base_unit_id' => $this->piece->id, 'factor' => '10', 'is_active' => true]);
        $box = Unit::query()->create(['company_id' => CompanyContext::id(), 'code' => 'TBOX', 'name_en' => 'Box',
            'base_unit_id' => $strip->id, 'factor' => '10', 'is_active' => true]);

        $medicine = Product::query()->create(['company_id' => CompanyContext::id(), 'code' => 'TMED',
            'name_en' => 'TMED', 'unit_id' => $strip->id, 'is_active' => true]);
        $this->pack($medicine, $this->carton, '5');

        $dozen = Unit::query()->where('code', 'DOZ')->value('id');
        $ids = array_column(app(PackConversion::class)->optionsFor([$medicine])[$medicine->id], 'id');

        // পাতার হিসাবে: বাক্স ১০ › কার্টন ৫ › ডজন ১.২ › পাতা ১ › পিস ০.১
        $this->assertSame([$box->id, $this->carton->id, $dozen, $strip->id, $this->piece->id], $ids);
    }

    /**
     * ⭐ মূল প্রমাণ: ব্যাকফিলের পরে **প্রতিটা পণ্য × প্রতিটা একক**-এ উত্তর
     * আগের কোডের হুবহু — factor, না-মেলার অস্বীকৃতি, আর ড্রপডাউনের তালিকা।
     *
     * ⓘ "আগের কোড" এখানে হাতে লেখা ([[legacyFactor()]], [[legacyOptions()]]),
     * ধাপ ৩-এর আগের `PackConversion` থেকে হুবহু। ⚠️ তুলনা নমুনা নয়, সব
     * জোড়া — লাইভে ধাপ ৩ পরীক্ষার সার্ভার ছাড়াই যাচ্ছে, তাই এটাই পাহারা।
     */
    public function test_after_the_backfill_every_answer_is_the_same_as_before(): void
    {
        // কেবল ব্যাকফিলের ছবি — এই ক্লাসের নিজের প্যাকগুলো সরিয়ে
        ProductUnit::query()->delete();
        app(\App\Modules\Inventory\Services\PackBackfill::class)->run(apply: true);

        $products = Product::query()->whereNotNull('unit_id')->get();
        $units = Unit::query()->active()->with('baseUnit')->get();
        $packs = app(PackConversion::class);
        $pairs = 0;

        foreach ($products as $product) {
            foreach ($units as $unit) {
                $old = $this->legacyFactor($product, $unit, $units);

                try {
                    $new = $packs->factorFor($product, $unit->id);
                } catch (ValidationException) {
                    $new = null;
                }

                $this->assertSame($old, $new, "{$product->code} × {$unit->code}: আগে ".var_export($old, true).', এখন '.var_export($new, true));
                $pairs++;
            }
        }

        $this->assertSame($this->legacyOptions($products, $units), $packs->optionsFor($products), 'ড্রপডাউনের তালিকা বদলেছে।');
        $this->assertGreaterThan(20, $pairs, 'পরীক্ষা অন্ধ: জোড়া খুব কম।');
    }

    /** ধাপ ৩-এর আগের factorFor() — না মিললে null। */
    private function legacyFactor(Product $product, Unit $entered, $units): ?string
    {
        $stocking = $units->firstWhere('id', $product->unit_id) ?? Unit::query()->with('baseUnit')->find($product->unit_id);

        if ($entered->rootUnitId() !== $stocking->rootUnitId()) {
            return null;
        }

        return bcdiv($entered->toBase('1'), $stocking->toBase('1'), 6);
    }

    /** ধাপ ৩-এর আগের optionsFor() — হুবহু। */
    private function legacyOptions($products, $units): array
    {
        $byRoot = $units
            ->groupBy(fn (Unit $unit) => $unit->rootUnitId())
            ->map(fn ($group) => $group
                ->sortByDesc(fn (Unit $unit) => (float) $unit->toBase('1'))
                ->map(fn (Unit $unit) => ['id' => $unit->id, 'label' => $unit->name()])
                ->values()
                ->all());

        $rootOf = $units->mapWithKeys(fn (Unit $unit) => [$unit->id => $unit->rootUnitId()]);
        $options = [];

        foreach ($products as $product) {
            $root = $rootOf[$product->unit_id] ?? null;

            if ($root === null || count($byRoot[$root] ?? []) < 2) {
                continue;
            }

            $options[$product->id] = $byRoot[$root];
        }

        return $options;
    }
}
