<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\ProductPackService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একক বদলাতে গেলে ফর্ম চারবার অস্বীকার করত।
 *
 * ── ⓘ মালিকের কথা, ২১ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * *"একক পরিবর্তন করতে গেলে এটা দেখায় কেন"* — আর পর্দায় একই বাক্য
 * **চারবার**: *"ডিফল্ট হিসেবে যে প্যাক বাছা হয়েছে, সেটা এই পণ্যের
 * টেবিলে নেই।"*
 *
 * ── ⛔ কেন চারবার ───────────────────────────────────────────────────
 * চারটা কাজের চারটা ডিফল্ট — কেনা, বেচা, POS, কাউন্টার
 * ([[ProductPackService::KINDS]])। প্রতিটার জন্য একটা করে অস্বীকার।
 *
 * ── ⛔ কেন হত ───────────────────────────────────────────────────────
 * "base" বাছলে রেডিওটা **শূন্য নয়, বেস এককের আইডিটাই** পাঠায়। আর
 * ঐ আইডিটা পাতা আঁকার সময়ে বসানো (`base: $product->unit_id`), তাই
 * উপরে একক বদলালেও রেডিওগুলো **পুরনো** এককের আইডি পাঠাত। সার্ভার
 * আগে নতুন এককটা বসায়, তারপর দেখে পাঠানো ডিফল্ট নতুন base-ও নয়,
 * প্যাকের তালিকাতেও নেই — চারটা অস্বীকার।
 */
final class TheUnitChangedAndTheFormRefusedFourTimesTest extends TestCase
{
    use RefreshDatabase;

    private Unit $piece;

    private Unit $litre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->piece = Unit::query()->where('code', 'PCS')->firstOrFail();
        $this->litre = Unit::query()->create([
            'company_id' => CompanyContext::id(), 'code' => 'TLTR', 'name_en' => 'Litre',
            'factor' => 1, 'allows_fraction' => true, 'is_active' => true,
        ]);
    }

    /**
     * ⭐ একক বদলালে সংরক্ষণ হয়, আর চারটা ডিফল্ট নতুন এককে যায়।
     *
     * ⚠️ দাবিটা দুইমুখী: অস্বীকার আসে না **আর** ডিফল্ট চারটা সত্যিই
     * নতুন এককে বসে। কেবল প্রথমটা দেখলে পরীক্ষাটা এমন কোডেও সবুজ
     * থাকত যেখানে ডিফল্টগুলো চুপচাপ পুরনো এককে পড়ে আছে।
     */
    public function test_changing_the_unit_carries_the_four_defaults_with_it(): void
    {
        $product = $this->aProductInPieces();

        $this->put(route('inventory.product.update', $product), [
            'name_en' => $product->name_en,
            'unit_id' => $this->litre->id,
            'pack_table' => '1',
            'packs' => [],

            /*
             * ⓘ পর্দা যা পাঠাত — সারানোর আগে **পুরনো** এককের আইডি।
             * ⛔ এখানেই চারটা অস্বীকার আসত।
             */
            'pack_defaults' => array_fill_keys(ProductPackService::KINDS, (string) $this->piece->id),
        ])->assertSessionHasNoErrors();

        $fresh = $product->fresh();

        $this->assertSame($this->litre->id, $fresh->unit_id, 'এককটাই বদলায়নি।');

        /*
         * ⓘ ডিফল্টগুলো `ProductUnit`-এর পতাকায় বসে, আর একক বদলালে পুরনো
         * base-এর সারিটা মুছে যায় ([[ProductPackService::sync()]])।
         */
        $row = ProductUnit::query()
            ->where('product_id', $product->id)
            ->where('unit_id', $this->litre->id)
            ->firstOrFail();

        foreach (['is_purchase_default', 'is_sales_default', 'is_pos_default', 'is_counter_default'] as $flag) {
            $this->assertTrue((bool) $row->{$flag}, "{$flag} নতুন এককে বসেনি।");
        }

        $this->assertFalse(
            ProductUnit::query()->where('product_id', $product->id)
                ->where('unit_id', $this->piece->id)->exists(),
            'পুরনো এককের সারিটা রয়ে গেছে।',
        );
    }

    /**
     * ⛔ আর সত্যিকারের অচেনা একক এখনো অস্বীকৃত — পাহারাটা আলগা হয়নি।
     *
     * ⚠️ এটা না দেখলে "সারানো" মানে হত পাহারাটা তুলে দেওয়া, আর তখন
     * যেকোনো আইডি বসিয়ে দিলেই বসে যেত।
     */
    public function test_a_unit_that_belongs_to_no_pack_is_still_refused(): void
    {
        $product = $this->aProductInPieces();
        $stranger = Unit::query()->where('code', 'CTN')->firstOrFail();

        $this->put(route('inventory.product.update', $product), [
            'name_en' => $product->name_en,
            'unit_id' => $this->piece->id,
            'pack_table' => '1',
            'packs' => [],
            'pack_defaults' => ['purchase' => (string) $stranger->id],
        ])->assertSessionHasErrors('pack_defaults.purchase');
    }

    private function aProductInPieces(): Product
    {
        $this->post(route('inventory.product.store'), [
            'name_en' => 'Cooking oil',
            'unit_id' => $this->piece->id,
            'pack_table' => '1',
            'packs' => [],
        ])->assertSessionHasNoErrors();

        return Product::query()->where('name_en', 'Cooking oil')->firstOrFail();
    }
}
