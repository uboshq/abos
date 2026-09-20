<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ পণ্যের ফর্মে প্যাকের টেবিল — ধাপ ৪খ, ২০ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ সংরক্ষণের নিয়ম আগের ধাপে মাপা ([[PacksAreSavedTheWayPeopleSayThemTest]])।
 * এখানে পর্দার দাবি: টেবিলটা আসে, আগের লেখা ফিরে আসে **মানুষ যেভাবে
 * লিখেছিলেন সেভাবেই** ("১২ বক্স", "২৮৮ পিস" নয়), আর একক ছাড়া পণ্যে
 * টেবিলের বদলে কারণটা লেখা থাকে।
 */
final class TheProductFormShowsItsOwnPacksTest extends TestCase
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

    private function milk(): Product
    {
        $product = Product::query()->create([
            'company_id' => CompanyContext::id(), 'code' => 'TMILK', 'name_en' => 'Dairy Milk',
            'unit_id' => $this->piece->id, 'is_active' => true,
        ]);

        $this->post(route('inventory.product.update', $product), [
            '_method' => 'PUT',
            'name_en' => 'Dairy Milk',
            'unit_id' => $this->piece->id,
            'packs' => [
                ['unit_id' => $this->box->id, 'per_qty' => '24', 'per_unit_id' => $this->piece->id],
                ['unit_id' => $this->carton->id, 'per_qty' => '12', 'per_unit_id' => $this->box->id, 'barcode' => 'CTN-1'],
            ],
            'pack_defaults' => ['purchase' => $this->carton->id],
        ])->assertSessionHasNoErrors();

        return $product->fresh();
    }

    /**
     * ⭐ সম্পাদনার ফর্মে আগের প্যাক — মালিকের নিজের কথায়: "১২", আর
     * "কিসের" ঘরে বক্স। ⛔ ২৮৮ নয়: সেটা মজুদের সংখ্যা, তাঁর বাক্য নয়।
     */
    public function test_the_form_gives_back_the_words_that_were_typed(): void
    {
        $milk = $this->milk();

        $page = $this->get(route('inventory.product.edit', $milk))->assertOk();

        $rows = $page->viewData('packRows');

        $this->assertSame([
            ['unit_id' => (string) $this->carton->id, 'per_qty' => '12',
                'per_unit_id' => (string) $this->box->id, 'barcode' => 'CTN-1'],
            ['unit_id' => (string) $this->box->id, 'per_qty' => '24',
                'per_unit_id' => (string) $this->piece->id, 'barcode' => ''],
        ], $rows, 'বড় প্যাক আগে, আর লেখা যেভাবে ছিল সেভাবে ফেরেনি।');

        // কেনায় কার্টন বাছা ছিল — রেডিওটা সেখানেই ফেরে
        $this->assertSame((string) $this->carton->id, $page->viewData('packDefaults')['purchase']);
        $this->assertSame((string) $this->piece->id, $page->viewData('packDefaults')['sales']);
    }

    /**
     * ⭐ পর্দায় টেবিলটা সত্যিই আছে — ঘরের নাম, লুকানো চিহ্ন, আর base-এর সারি।
     */
    public function test_the_table_is_on_the_screen_with_its_hidden_marker(): void
    {
        $page = $this->get(route('inventory.product.edit', $this->milk()))->assertOk();

        $page->assertSee('productPacks(', escape: false);
        $page->assertSee("'packs[' + i + '][per_qty]'", escape: false);
        $page->assertSee('name="pack_table"', escape: false);
        $page->assertSee('name="pack_defaults[purchase]"', escape: false);
        $page->assertSee(__('inventory::pack.title'));
        // base-এর নাম বাঁধা সারিতে
        $page->assertSee($this->piece->name());
    }

    /**
     * ⛔ একক ছাড়া পণ্যে টেবিল নয়, কারণটা — "১ কার্টন = ২৪ কী?"
     */
    public function test_without_a_unit_the_reason_is_shown_instead(): void
    {
        $page = $this->get(route('inventory.product.create'))->assertOk();

        $page->assertSee(__('inventory::pack.needs_unit'));
        $page->assertDontSee('name="pack_table"', escape: false);
    }

    /**
     * ⭐ ব্যাকফিলের সারিতে "কিসের" লেখা নেই — তখন ঘরটা base ধরে, আর
     * সংখ্যাটা base-এ কত সেটাই।
     */
    public function test_a_backfilled_pack_reads_as_so_many_base_units(): void
    {
        $product = Product::query()->create([
            'company_id' => CompanyContext::id(), 'code' => 'TOLD', 'name_en' => 'Old',
            'unit_id' => $this->piece->id, 'is_active' => true,
        ]);

        ProductUnit::query()->create([
            'company_id' => CompanyContext::id(), 'product_id' => $product->id,
            'unit_id' => $this->carton->id, 'factor' => '24', 'is_active' => true,
        ]);

        $rows = $this->get(route('inventory.product.edit', $product))->assertOk()->viewData('packRows');

        $this->assertSame([[
            'unit_id' => (string) $this->carton->id,
            'per_qty' => '24',
            'per_unit_id' => (string) $this->piece->id,
            'barcode' => '',
        ]], $rows);
    }
}
