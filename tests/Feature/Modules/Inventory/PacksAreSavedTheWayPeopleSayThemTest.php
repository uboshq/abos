<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ প্যাক জমা পড়ে মানুষ যেভাবে বলেন — ধাপ ৪ক, ১৯ সেপ্টেম্বর ২০২৬।
 *
 * মালিকের উদাহরণ: *"Dairy Milk Chocolate 24 pcs e ek box, 12 box e 1 ctn"*।
 * ⓘ ফর্মে লেখা হয় "কার্টন = ১২ বক্স", মজুদ পড়ে ২৮৮ পিস — দুইটাই এখানে
 * মাপা। সব দাবি আসল ফর্মের পথ (store/update) দিয়ে, সার্ভিসে সরাসরি নয়।
 */
final class PacksAreSavedTheWayPeopleSayThemTest extends TestCase
{
    use RefreshDatabase;

    private Unit $piece;

    private Unit $box;

    private Unit $carton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->piece = Unit::query()->where('code', 'PCS')->firstOrFail();
        $this->carton = Unit::query()->where('code', 'CTN')->firstOrFail();
        $this->box = Unit::query()->create([
            'company_id' => CompanyContext::id(), 'code' => 'TBOX', 'name_en' => 'Box',
            'factor' => 1, 'allows_fraction' => false, 'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function create(array $packs, array $extra = [])
    {
        return $this->post(route('inventory.product.store'), [
            'name_en' => 'Dairy Milk',
            'unit_id' => $this->piece->id,
            'packs' => $packs,
            ...$extra,
        ]);
    }

    private function dairyMilk(): Product
    {
        return Product::query()->where('name_en', 'Dairy Milk')->firstOrFail();
    }

    private function pack(Product $product, Unit $unit): ?ProductUnit
    {
        return ProductUnit::query()->where('product_id', $product->id)->where('unit_id', $unit->id)->first();
    }

    /**
     * ⭐ মালিকের নিজের বাক্য: বক্স = ২৪ পিস, কার্টন = ১২ বক্স → ২৮৮ পিস।
     * ⓘ লেখাটাও থাকে (১২ বক্স), যাতে ফর্ম আবার খুললে সেটাই দেখায়।
     */
    public function test_a_carton_of_twelve_boxes_of_twenty_four_is_288_pieces(): void
    {
        $this->create([
            ['unit_id' => $this->box->id, 'per_qty' => '24', 'per_unit_id' => $this->piece->id],
            ['unit_id' => $this->carton->id, 'per_qty' => '12', 'per_unit_id' => $this->box->id],
        ], ['pack_defaults' => ['purchase' => $this->carton->id]])->assertSessionHasNoErrors();

        $milk = $this->dairyMilk();

        $this->assertSame('24.000000', $this->pack($milk, $this->box)->factor);
        $this->assertSame('288.000000', $this->pack($milk, $this->carton)->factor);
        $this->assertSame('12.000000', $this->pack($milk, $this->carton)->per_qty);
        $this->assertSame($this->box->id, $this->pack($milk, $this->carton)->per_unit_id);

        $base = $this->pack($milk, $this->piece);
        $this->assertSame('1.000000', $base->factor, 'base সারি নেই বা ১ নয়।');

        // কেনায় কার্টন, বাকি তিন কাজে base
        $this->assertTrue($this->pack($milk, $this->carton)->is_purchase_default);
        $this->assertFalse($base->is_purchase_default);
        $this->assertTrue($base->is_sales_default && $base->is_pos_default && $base->is_counter_default);
    }

    /**
     * ⭐ ক্রম মেনে লিখতে হয় না — কার্টন আগে, বক্স পরে লিখলেও একই।
     */
    public function test_the_rows_may_come_in_any_order(): void
    {
        $this->create([
            ['unit_id' => $this->carton->id, 'per_qty' => '12', 'per_unit_id' => $this->box->id],
            ['unit_id' => $this->box->id, 'per_qty' => '24', 'per_unit_id' => $this->piece->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame('288.000000', $this->pack($this->dairyMilk(), $this->carton)->factor);
    }

    /**
     * ⛔ পাঁচ রকম ভুল, এক জমায় — সবগুলোই একসাথে ফেরে, আর পণ্যটাও তৈরি হয় না।
     */
    public function test_every_mistake_comes_back_at_once_and_nothing_is_saved(): void
    {
        $dozen = Unit::query()->where('code', 'DOZ')->firstOrFail();
        $bag = Unit::query()->where('code', 'BAG')->firstOrFail();

        $this->create([
            0 => ['unit_id' => $this->piece->id, 'per_qty' => '1'],                                   // base নিজে
            1 => ['unit_id' => $this->box->id, 'per_qty' => '2.5', 'per_unit_id' => $this->piece->id], // আধখানা পিস
            2 => ['unit_id' => $this->box->id, 'per_qty' => '10'],                                    // দুইবার
            3 => ['unit_id' => $dozen->id, 'per_qty' => '0'],                                         // শূন্য
            4 => ['unit_id' => $bag->id, 'per_qty' => '5', 'per_unit_id' => $bag->id],               // নিজেকে দিয়ে
        ])->assertSessionHasErrors([
            'packs.0.unit_id', 'packs.2.unit_id', 'packs.3.per_qty', 'packs.4.per_unit_id',
        ]);

        $this->assertNull(Product::query()->where('name_en', 'Dairy Milk')->first(), 'ভুল প্যাকেও পণ্য তৈরি হয়ে গেছে।');
    }

    /**
     * ⛔ আধখানা পিস — পিস ভাঙে না, তাই "১ বক্স = ২.৫ পিস" নয়।
     */
    public function test_a_pack_cannot_hold_half_a_piece(): void
    {
        $this->create([
            ['unit_id' => $this->box->id, 'per_qty' => '2.5', 'per_unit_id' => $this->piece->id],
        ])->assertSessionHasErrors('packs.0.per_qty');
    }

    /**
     * ⛔ চক্র — কার্টন বক্সে, বক্স কার্টনে। শেষ হয় না।
     */
    public function test_packs_measured_in_each_other_are_refused(): void
    {
        $this->create([
            ['unit_id' => $this->carton->id, 'per_qty' => '12', 'per_unit_id' => $this->box->id],
            ['unit_id' => $this->box->id, 'per_qty' => '2', 'per_unit_id' => $this->carton->id],
        ])->assertSessionHasErrors(['packs.0.per_unit_id', 'packs.1.per_unit_id']);
    }

    /**
     * ⛔ বারকোড: অন্য পণ্যের পিসের নম্বর কার্টনে নয়, আর কারো কার্টনের নম্বর
     * অন্য পণ্যের পিসে নয় — দুই দিকেই।
     */
    public function test_a_barcode_is_not_shared_between_a_pack_and_a_product(): void
    {
        $other = Product::query()->whereNotNull('barcode')->orderBy('id')->firstOrFail();

        $this->create([
            ['unit_id' => $this->carton->id, 'per_qty' => '12', 'per_unit_id' => $this->piece->id, 'barcode' => $other->barcode],
        ])->assertSessionHasErrors('packs.0.barcode');

        $this->create([
            ['unit_id' => $this->carton->id, 'per_qty' => '12', 'per_unit_id' => $this->piece->id, 'barcode' => 'CTN-777'],
        ])->assertSessionHasNoErrors();

        $this->post(route('inventory.product.store'), [
            'name_en' => 'Another', 'unit_id' => $this->piece->id, 'barcode' => 'CTN-777',
        ])->assertSessionHasErrors('barcode');
    }

    /**
     * ⭐ কার্টন আর বক্সের বারকোড অদলবদল এক জমায় — মাঝপথে unique আপত্তি নয়।
     */
    public function test_two_packs_can_swap_barcodes_in_one_save(): void
    {
        $this->create([
            ['unit_id' => $this->box->id, 'per_qty' => '24', 'per_unit_id' => $this->piece->id, 'barcode' => 'B-1'],
            ['unit_id' => $this->carton->id, 'per_qty' => '12', 'per_unit_id' => $this->box->id, 'barcode' => 'C-1'],
        ])->assertSessionHasNoErrors();

        $milk = $this->dairyMilk();

        $this->put(route('inventory.product.update', $milk), [
            'name_en' => 'Dairy Milk',
            'unit_id' => $this->piece->id,
            'packs' => [
                ['unit_id' => $this->box->id, 'per_qty' => '24', 'per_unit_id' => $this->piece->id, 'barcode' => 'C-1'],
                ['unit_id' => $this->carton->id, 'per_qty' => '12', 'per_unit_id' => $this->box->id, 'barcode' => 'B-1'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame('C-1', $this->pack($milk, $this->box)->barcode);
        $this->assertSame('B-1', $this->pack($milk, $this->carton)->barcode);
    }

    /**
     * ⭐ ফর্ম থেকে সারি সরালে প্যাকও যায় — base থাকে।
     * ⭐ আর যে জমায় প্যাকের টেবিলই নেই, সেটা প্যাক ছোঁয় না।
     */
    public function test_a_removed_row_goes_and_a_save_without_the_table_leaves_packs_alone(): void
    {
        $this->create([
            ['unit_id' => $this->box->id, 'per_qty' => '24', 'per_unit_id' => $this->piece->id],
            ['unit_id' => $this->carton->id, 'per_qty' => '12', 'per_unit_id' => $this->box->id],
        ])->assertSessionHasNoErrors();

        $milk = $this->dairyMilk();

        $this->put(route('inventory.product.update', $milk), [
            'name_en' => 'Dairy Milk', 'unit_id' => $this->piece->id, 'sale_price' => '5',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($this->pack($milk, $this->carton), 'টেবিল ছাড়া জমা প্যাক মুছে দিয়েছে।');

        $this->put(route('inventory.product.update', $milk), [
            'name_en' => 'Dairy Milk', 'unit_id' => $this->piece->id,
            'packs' => [['unit_id' => $this->box->id, 'per_qty' => '24', 'per_unit_id' => $this->piece->id]],
        ])->assertSessionHasNoErrors();

        $this->assertNull($this->pack($milk, $this->carton), 'সরানো কার্টন রয়ে গেছে।');
        $this->assertNotNull($this->pack($milk, $this->piece), 'base সারি চলে গেছে।');
    }

    /**
     * ⭐ ফর্মে সব সারি মুছে জমা — ব্রাউজার `packs` পাঠায়ই না, কিন্তু
     * `pack_table` চিহ্নটা বলে টেবিলটা ছিল, তাই শেষ প্যাকটাও যায়।
     */
    public function test_clearing_every_row_removes_the_last_pack_too(): void
    {
        $this->create([
            ['unit_id' => $this->box->id, 'per_qty' => '24', 'per_unit_id' => $this->piece->id],
        ])->assertSessionHasNoErrors();

        $milk = $this->dairyMilk();

        $this->put(route('inventory.product.update', $milk), [
            'name_en' => 'Dairy Milk', 'unit_id' => $this->piece->id, 'pack_table' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertNull($this->pack($milk, $this->box), 'শেষ প্যাকটা মোছা যায়নি।');
        $this->assertNotNull($this->pack($milk, $this->piece));
    }

    /**
     * ⛔ মজুদ-চলাচল হয়ে গেলে একক আর বদলায় না — আর কারণটা বলা হয়।
     */
    public function test_the_unit_is_locked_once_stock_has_moved(): void
    {
        $moved = Product::query()->whereIn('id', StockMovement::query()->select('product_id'))
            ->where('unit_id', $this->piece->id)->orderBy('id')->firstOrFail();

        $this->put(route('inventory.product.update', $moved), [
            'name_en' => $moved->name_en, 'unit_id' => $this->box->id, 'packs' => [],
        ])->assertSessionHasErrors('unit_id');

        $this->assertSame($this->piece->id, $moved->fresh()->unit_id);
    }

    /**
     * ⭐ কিছুই না চললে একক বদলানো যায় — আর পুরনো base-এর সারি নতুনটায় বদলায়।
     */
    public function test_an_unused_product_may_change_its_unit(): void
    {
        $this->create([])->assertSessionHasNoErrors();
        $milk = $this->dairyMilk();

        $this->put(route('inventory.product.update', $milk), [
            'name_en' => 'Dairy Milk', 'unit_id' => $this->box->id, 'packs' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->box->id, $milk->fresh()->unit_id);
        $this->assertNotNull($this->pack($milk, $this->box));
        $this->assertNull($this->pack($milk, $this->piece), 'পুরনো base-এর সারি রয়ে গেছে।');
    }

    /**
     * ⛔ একক ছাড়া পণ্য নয় — মালিকের সিদ্ধান্ত।
     */
    public function test_a_product_needs_a_unit(): void
    {
        $this->post(route('inventory.product.store'), ['name_en' => 'No unit'])
            ->assertSessionHasErrors('unit_id');
    }
}
