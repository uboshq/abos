<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ লাইন খোলে সেই এককে, যেটা মালিক বেছে রেখেছেন — ধাপ ৫,
 * ২০ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ গুদামে কেনা হয় কার্টনে, দোকানে বেচা হয় পিসে — একই পণ্য। তাই পণ্যের
 * ফর্মের চারটা রেডিও (কেনায় · বেচায় · POS-এ · কাউন্টারে) যা বলে, ক্রয়,
 * বিক্রয় আর কাউন্টারের পর্দা কেবল সেটাই মেনে চলে।
 *
 * ⚠️ কোনো অনুমান বসে না: কেউ কিছু না বাছলে ঘরটা খালি থাকে, আর সার্ভার
 * আগের মতোই পণ্যের নিজের একক ধরে। ⛔ ভুল ডিফল্ট কোনো ডিফল্টের চেয়ে
 * খারাপ — ঘরটা ভরা থাকে বলে কেউ তাকায় না।
 */
final class TheLineOpensInTheUnitTheOwnerChoseTest extends TestCase
{
    use RefreshDatabase;

    private Unit $piece;

    private Unit $carton;

    private Product $soap;

    private Product $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(SettingsService::class)->set('inventory.pack_entry_enabled', true);

        $this->piece = Unit::query()->where('code', 'PCS')->firstOrFail();
        $this->carton = Unit::query()->where('code', 'CTN')->firstOrFail();

        $this->soap = Product::query()->create([
            'company_id' => CompanyContext::id(), 'code' => 'TSOAP', 'name_en' => 'Soap',
            'unit_id' => $this->piece->id, 'is_active' => true,
        ]);

        // কেনায় কার্টন, বেচায় পিস — মালিকের বাছাই
        $this->pack($this->soap, $this->piece, '1', ['is_sales_default' => true, 'is_pos_default' => true]);
        $this->pack($this->soap, $this->carton, '24', ['is_purchase_default' => true, 'is_counter_default' => true]);

        // আর একটা পণ্য, যেখানে কেউ কিছু বাছেনি
        $this->plain = Product::query()->create([
            'company_id' => CompanyContext::id(), 'code' => 'TPLAIN', 'name_en' => 'Plain',
            'unit_id' => $this->piece->id, 'is_active' => true,
        ]);

        $this->pack($this->plain, $this->carton, '12', []);
    }

    private function pack(Product $product, Unit $unit, string $factor, array $flags): ProductUnit
    {
        return ProductUnit::query()->create([
            'company_id' => CompanyContext::id(),
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'factor' => $factor,
            'is_active' => true,
            ...$flags,
        ]);
    }

    /**
     * ⭐ একই পণ্য, দুই কাগজে দুই একক — কেনায় কার্টন, বেচায় পিস।
     */
    public function test_buying_opens_in_cartons_and_selling_in_pieces(): void
    {
        $packs = app(PackConversion::class);

        $this->assertSame([$this->soap->id => $this->carton->id],
            $packs->defaultsFor([$this->soap], 'purchase'));

        $this->assertSame([$this->soap->id => $this->piece->id],
            $packs->defaultsFor([$this->soap], 'sales'));

        $this->assertSame([$this->soap->id => $this->carton->id],
            $packs->defaultsFor([$this->soap], 'counter'));
    }

    /**
     * ⭐ কেউ কিছু না বাছলে সারিটাই আসে না — পর্দা তখন আগের মতোই চলে।
     */
    public function test_a_product_with_no_choice_has_no_default(): void
    {
        $this->assertSame([], app(PackConversion::class)->defaultsFor([$this->plain], 'purchase'));
    }

    /**
     * ⛔ নিষ্ক্রিয় প্যাক ডিফল্ট হতে পারে না — ঘরটা এমন একটা এককে খুলত
     * যেটা ড্রপডাউনেই নেই।
     */
    public function test_an_inactive_pack_is_not_offered_as_a_default(): void
    {
        ProductUnit::query()->where('product_id', $this->soap->id)
            ->where('unit_id', $this->carton->id)->update(['is_active' => false]);

        $this->assertSame([], app(PackConversion::class)->defaultsFor([$this->soap], 'purchase'));
    }

    /**
     * ⛔ অচেনা কাজের নাম চুপচাপ কিছু ফেরায় না।
     */
    public function test_an_unknown_kind_returns_nothing(): void
    {
        $this->assertSame([], app(PackConversion::class)->defaultsFor([$this->soap], 'warehouse'));
    }

    /**
     * ⭐ পর্দায় সত্যিই পৌঁছায় — ক্রয়ের ফর্মে "কেনায়" বাছাই, বিক্রয়ের ফর্মে
     * "বেচায়"।
     */
    public function test_the_forms_carry_the_right_default(): void
    {
        $buying = $this->get(route('purchase.bill.create'))->assertOk();
        $selling = $this->get(route('sales.invoice.create'))->assertOk();

        /*
         * ⓘ `@js(...)` অ্যাট্রিবিউটের ভিতরে বসে, তাই উদ্ধৃতিগুলো `&quot;` হয়ে
         * যায় — পর্দায় লেখাটা দেখতে হয় `packDefaults: {&quot;7&quot;:3}`।
         * ⚠️ কাঁচা `"7":3` খুঁজলে দাবিটা কখনোই সবুজ হত না, আর সেটা মনে হত
         * "ডিফল্ট পৌঁছায়নি" — অথচ পৌঁছেছে।
         */
        $buying->assertSee('&quot;'.$this->soap->id.'&quot;:'.$this->carton->id, escape: false);
        $selling->assertSee('&quot;'.$this->soap->id.'&quot;:'.$this->piece->id, escape: false);

        // ⛔ আর উল্টোটা যেন না বসে: ক্রয়ে পিস নয়, বিক্রয়ে কার্টন নয়
        $buying->assertDontSee('&quot;'.$this->soap->id.'&quot;:'.$this->piece->id, escape: false);
        $selling->assertDontSee('&quot;'.$this->soap->id.'&quot;:'.$this->carton->id, escape: false);
    }
}
