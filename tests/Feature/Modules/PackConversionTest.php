<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বাক্স, পাতা, পিস — এক পণ্য তিনভাবে যায়।
 *
 * ওষুধের দোকানে হোলসেলে বাক্স, খুচরায় পাতা, আর কেউ চাইলে একটা পিস।
 * লাইনে যে যেভাবে লিখেছেন সেভাবে জমা থাকলে মজুদের প্রতিটা প্রশ্নে আগে
 * একক দেখে গুণ করতে হত, আর একবার সেই গুণ বাদ পড়লেই ১০ পাতা আর ১০
 * বাক্স এক হয়ে যেত।
 */
class PackConversionTest extends TestCase
{
    use RefreshDatabase;

    private Unit $piece;

    private Unit $strip;

    private Unit $box;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ব্যবহারকারীর দেওয়া সিঁড়ি: ১ বাক্স = ১০ পাতা, ১ পাতা = ১০ পিস
        $this->piece = Unit::query()->where('code', 'PCS')->firstOrFail();
        $this->strip = $this->unit('PATA', 'Strip', $this->piece->id, '10');
        $this->box = $this->unit('BOX', 'Box', $this->strip->id, '10');

        $this->product = Product::query()->firstOrFail();
        $this->product->forceFill(['unit_id' => $this->piece->id])->save();
    }

    private function unit(string $code, string $name, ?int $baseId, string $factor, bool $fraction = false): Unit
    {
        return Unit::query()->create([
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'base_unit_id' => $baseId,
            'factor' => $factor,
            'allows_fraction' => $fraction,
            'is_active' => true,
        ]);
    }

    private function packs(): PackConversion
    {
        return app(PackConversion::class);
    }

    private function assertQty(string $expected, string $actual): void
    {
        $this->assertSame(0, bccomp($expected, $actual, 6), "{$actual} ≠ {$expected}");
    }

    // ── রূপান্তর ─────────────────────────────────────────────────

    /** ২ বাক্স মানে ২০০ পিস — ব্যবহারকারীর বলা অঙ্কটাই। */
    public function test_a_box_is_a_hundred_pieces(): void
    {
        $this->assertQty('200', $this->packs()->toStockQty($this->product, '2', $this->box->id));
    }

    public function test_a_strip_is_ten_pieces(): void
    {
        $this->assertQty('30', $this->packs()->toStockQty($this->product, '3', $this->strip->id));
    }

    /**
     * একক না এলে সংখ্যাটা যেমন আছে তেমনই থাকে।
     *
     * পুরনো পর্দা, ইমপোর্ট আর পরীক্ষার কোড কেউ একক পাঠায় না — তাদের
     * কিছু বদলানো চলবে না, নাহলে চালু ব্যবসার মজুদ একদিনে নড়ে যেত।
     */
    public function test_no_unit_means_no_conversion(): void
    {
        $this->assertQty('7', $this->packs()->toStockQty($this->product, '7', null));
    }

    public function test_the_products_own_unit_changes_nothing(): void
    {
        $this->assertQty('7', $this->packs()->toStockQty($this->product, '7', $this->piece->id));
    }

    /**
     * পণ্যটা সিঁড়ির মাঝখানে গোনা হলেও অঙ্ক ঠিক থাকে।
     *
     * পাতায় গোনা পণ্যে ২ বাক্স = ২০ পাতা, ২০০ নয়। সরাসরি এককের
     * factor গুণ করলে এখানেই ভুলটা হত — তাই দুইটাই গোড়ায় নামিয়ে ভাগ
     * করা হয়।
     */
    public function test_a_product_counted_mid_ladder_still_adds_up(): void
    {
        $this->product->forceFill(['unit_id' => $this->strip->id])->save();

        $this->assertQty('20', $this->packs()->toStockQty($this->product->fresh(), '2', $this->box->id));
    }

    // ── অস্বীকার ─────────────────────────────────────────────────

    /** পিস আর কেজির গোড়া আলাদা — বদলানো মানে একটা বানানো সংখ্যা। */
    public function test_unrelated_units_are_refused(): void
    {
        $kg = Unit::query()->where('code', 'KG')->firstOrFail();

        $this->expectException(ValidationException::class);

        $this->packs()->toStockQty($this->product, '5', $kg->id);
    }

    /**
     * ছোট প্যাকে লেখা বড় এককে গোনা পণ্য — আধখানা বাক্স হয় না।
     *
     * না আটকালে মজুদে ০.৫ বাক্স বসত, আর গোনার সময় কেউ মেলাতে পারত না।
     */
    public function test_a_whole_pack_that_does_not_divide_is_refused(): void
    {
        $this->product->forceFill(['unit_id' => $this->box->id])->save();

        $this->expectException(ValidationException::class);

        $this->packs()->toStockQty($this->product->fresh(), '5', $this->piece->id);
    }

    /** ভগ্নাংশ চালু থাকলে ভাঙা যায় — কেজি ওভাবেই চলে। */
    public function test_a_unit_that_allows_fractions_may_split(): void
    {
        $gram = $this->unit('GM', 'Gram', null, '1', fraction: true);
        $kilo = $this->unit('KGX', 'Kilo', $gram->id, '1000', fraction: true);

        $this->product->forceFill(['unit_id' => $kilo->id])->save();

        $this->assertQty('0.25', $this->packs()->toStockQty($this->product->fresh(), '250', $gram->id));
    }

    public function test_an_unknown_unit_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->packs()->toStockQty($this->product, '1', 999999);
    }

    /** একক ছাড়া পণ্যে রূপান্তরের কিছু নেই — নীরবে ধরে নেওয়া হয় না। */
    public function test_a_product_with_no_unit_is_refused(): void
    {
        $this->product->forceFill(['unit_id' => null])->save();

        $this->expectException(ValidationException::class);

        $this->packs()->toStockQty($this->product->fresh(), '1', $this->box->id);
    }

    // ── তালিকা ও লেখা ────────────────────────────────────────────

    /** যে এককে লেখা যায় — গোড়া এক, এমন সব; বড়টা আগে। */
    public function test_the_pick_list_holds_the_whole_ladder_biggest_first(): void
    {
        $codes = $this->packs()->unitsFor($this->product)->pluck('code')->all();

        $this->assertSame(['BOX', 'PATA', 'PCS'], array_values(array_intersect($codes, ['BOX', 'PATA', 'PCS'])));
    }

    /** কেজি ওই তালিকায় নেই — ওটা অন্য সিঁড়ি। */
    public function test_the_pick_list_leaves_out_another_ladder(): void
    {
        $this->assertNotContains('KG', $this->packs()->unitsFor($this->product)->pluck('code')->all());
    }

    public function test_the_printed_line_says_what_was_typed_and_what_it_means(): void
    {
        // নামটা চলতি ভাষায় আসে — খাতা যে ভাষায় ছাপা, লাইনও সে ভাষায়
        $this->assertSame(
            '2 '.$this->box->name().' (200 '.$this->piece->name().')',
            $this->packs()->describe($this->product, '200', '2', $this->box->id),
        );
    }

    /** এক এককে লেখা হলে বন্ধনী আসে না — "১০ পিস (১০ পিস)" কেউ পড়ে না। */
    public function test_a_line_in_the_stocking_unit_says_it_once(): void
    {
        $this->assertSame(
            '10 '.$this->piece->name(),
            $this->packs()->describe($this->product, '10', '10', $this->piece->id),
        );
    }
}
