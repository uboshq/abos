<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Models\PriceListItem;
use App\Modules\Sales\Services\SalesPrice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একজন ডিলার সবসময় তাকের দামই দিতেন — দর তালিকার পণ্যপ্রতি দর ([[SalesPrice]], মালিক, ৫ অক্টোবর ২০২৬)।
 *
 * ⭐ সবচেয়ে নির্দিষ্টটা জেতে: গ্রাহক › ধরন › এলাকা › সবার তালিকা › পণ্যের দাম; একই স্তরে নতুন মেয়াদ।
 * ⓘ প্রতিটা দাবি নিচের স্তরটা জীবিত রেখে উপরেরটা যোগ করে — নাহলে "উপরেরটা জেতে" আর "উপরেরটাই একমাত্র" আলাদা করা যেত না।
 */
final class ADealerWasAlwaysChargedTheShelfPriceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Product $product;

    private SalesPrice $prices;

    private Location $area;

    private Location $point;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->area = Location::query()->create(['code' => 'PLA', 'name_en' => 'Price Area', 'level' => Location::AREA]);
        $this->point = Location::query()->create(['code' => 'PLP', 'name_en' => 'Price Point', 'level' => Location::POINT, 'parent_id' => $this->area->id]);

        $tier = PartyType::query()->create(['code' => 'PLDLR', 'name_en' => 'Price Dealer', 'applies_to' => PartyType::CUSTOMER]);

        $this->customer = Customer::query()->firstOrFail();
        $this->customer->forceFill(['party_type_id' => $tier->id, 'location_id' => $this->point->id])->save();
        $this->customer->refresh();

        $this->product = Product::query()->whereNotNull('unit_id')->orderBy('id')->firstOrFail();
        $this->product->forceFill(['sale_price' => '100'])->save();
        $this->product->refresh();

        $this->prices = app(SalesPrice::class);
    }

    /** ⓘ কোনো তালিকা নেই — পণ্যের নিজের দাম, আর উৎস "পণ্যের দাম"। */
    public function test_with_no_list_the_product_price_stands(): void
    {
        $p = $this->prices->for($this->customer, $this->product);

        $this->assertSame(['100.0000', SalesPrice::STANDARD], [$p->price, $p->source]);
        $this->assertSame(__('sales::price_source.standard'), $p->label());
    }

    /** ⭐ ধাপে ধাপে: সবার › এলাকা › ধরন › গ্রাহক — প্রতিবার নিচেরটা রেখেই। */
    public function test_the_most_specific_list_wins_step_by_step(): void
    {
        $this->list('ALL', [], '95');
        $this->assertResolved('95.0000', SalesPrice::ALL);

        $this->list('AREA', ['location_id' => $this->area->id], '94');
        $this->assertResolved('94.0000', SalesPrice::TERRITORY);

        $this->list('TIER', ['party_type_id' => $this->customer->party_type_id], '93');
        $this->assertResolved('93.0000', SalesPrice::TIER);

        $this->list('MINE', ['customer_id' => $this->customer->id], '92');
        $this->assertResolved('92.0000', SalesPrice::CUSTOMER);

        $this->assertSame(__('sales::price_source.customer'), $this->prices->for($this->customer, $this->product)->label());
    }

    /** ⛔ অন্য গ্রাহকের, অন্য ধরনের, অন্য এলাকার তালিকা এই গ্রাহকে খাটে না। */
    public function test_lists_aimed_at_someone_else_do_not_apply(): void
    {
        $other = Customer::query()->whereKeyNot($this->customer->id)->firstOrFail();
        $otherTier = PartyType::query()->create(['code' => 'PLOTH', 'name_en' => 'Other Tier', 'applies_to' => PartyType::CUSTOMER]);
        $elsewhere = Location::query()->create(['code' => 'PLX', 'name_en' => 'Elsewhere', 'level' => Location::AREA]);

        $this->list('OTH1', ['customer_id' => $other->id], '50');
        $this->list('OTH2', ['party_type_id' => $otherTier->id], '51');
        $this->list('OTH3', ['location_id' => $elsewhere->id], '52');

        $this->assertResolved('100.0000', SalesPrice::STANDARD);
    }

    /** ⭐ এলাকায় কাছের ধাপটা আগে — দোকানের পয়েন্ট তার এরিয়ার আগে, এরিয়ার সারি নতুন হলেও। */
    public function test_the_nearest_place_wins_inside_the_territory_level(): void
    {
        $this->list('PT', ['location_id' => $this->point->id], '91', now()->subDays(10));
        $this->list('AR', ['location_id' => $this->area->id], '90', now()->subDay());

        $this->assertResolved('91.0000', SalesPrice::TERRITORY);
    }

    /** ⭐ একই স্তরে নতুন `valid_from` জেতে; ভবিষ্যতের আর মেয়াদ-শেষের সারি খাটে না। */
    public function test_within_a_level_the_latest_valid_from_wins_and_dates_are_honoured(): void
    {
        $list = $this->list('C1', ['customer_id' => $this->customer->id], '80', now()->subDays(30));
        $this->item($list, '81', now()->subDays(5));
        $this->item($list, '70', now()->addDay());                         // ⛔ কাল থেকে
        $this->item($list, '60', now()->subDays(3), now()->subDay());      // ⛔ গতকাল শেষ
        $this->item($list, '79', now()->subDays(20));                     // ⓘ পরে লেখা, কিন্তু পুরনো মেয়াদ — হারে

        $this->assertResolved('81.0000', SalesPrice::CUSTOMER);

        // ⓘ তারিখ দিলে সেই দিনের দর — কালকেরটা কাল খাটে
        $this->assertSame('70.0000', $this->prices->for($this->customer, $this->product, now()->addDays(2))->price);
        $this->assertSame('79.0000', $this->prices->for($this->customer, $this->product, now()->subDays(10))->price);
        $this->assertSame('80.0000', $this->prices->for($this->customer, $this->product, now()->subDays(25))->price);
    }

    /** ⛔ অন্য গ্রাহকের তালিকার স্তর নেই — কোয়েরির ছাঁকনি ছাড়াও [[SalesPrice::level()]] নিজেই বলে। */
    public function test_the_level_of_another_customers_list_is_none(): void
    {
        $other = Customer::query()->whereKeyNot($this->customer->id)->firstOrFail();
        $list = $this->list('OTH', ['customer_id' => $other->id], null);

        $this->assertNull($this->prices->level($list, $this->customer));
        $this->assertSame(SalesPrice::CUSTOMER, $this->prices->level($list, $other));
        $this->assertSame(SalesPrice::CUSTOMER, SalesPrice::targetOf($list));
    }

    /** ⛔ বন্ধ তালিকা খাটে না — নিচের স্তরে নামে। */
    public function test_an_inactive_list_is_skipped(): void
    {
        $this->list('ALL', [], '95');
        $this->list('MINE', ['customer_id' => $this->customer->id], '92')->update(['is_active' => false]);

        $this->assertResolved('95.0000', SalesPrice::ALL);
    }

    /** ⓘ গ্রাহক ছাড়া (হাঁটা খদ্দের) — কেবল সবার তালিকা, নাহলে পণ্যের দাম। */
    public function test_without_a_customer_only_the_list_for_all_applies(): void
    {
        $this->list('MINE', ['customer_id' => $this->customer->id], '92');
        $this->assertSame(SalesPrice::STANDARD, $this->prices->for(null, $this->product)->source);

        $this->list('ALL', [], '95');
        $this->assertSame(['95.0000', SalesPrice::ALL], [$this->prices->for(null, $this->product)->price, $this->prices->for(null, $this->product)->source]);
    }

    /** ⭐ কার্টনের দর পিসে নামে, আর কার্টনে চাইলে কার্টনের দরই। */
    public function test_a_pack_price_answers_in_the_unit_asked_for(): void
    {
        $carton = Unit::query()->create(['code' => 'PLCTN', 'name_en' => 'Price Carton', 'factor' => '1']);
        ProductUnit::query()->create(['product_id' => $this->product->id, 'unit_id' => $carton->id, 'factor' => '12', 'is_active' => true]);

        $list = $this->list('MINE', ['customer_id' => $this->customer->id], null);
        PriceListItem::query()->create([
            'price_list_id' => $list->id, 'product_id' => $this->product->id, 'unit_id' => $carton->id,
            'price' => '1080', 'valid_from' => now()->subDay()->toDateString(),
        ]);

        $this->assertResolved('90.0000', SalesPrice::CUSTOMER);
        $this->assertSame('1080.0000', $this->prices->for($this->customer, $this->product, null, $carton->id)->price);

        // ⓘ তালিকা না থাকলে পণ্যের দামও কার্টনে গুণ হয়
        $this->assertSame('1200.0000', $this->prices->for(null, $this->product, null, $carton->id)->price);
    }

    /** ⓘ অনেক পণ্য একবারে — প্রতিটার নিজের উত্তর, তালিকা নেই এমনটার পণ্যের দাম। */
    public function test_many_products_resolve_at_once(): void
    {
        $second = Product::query()->whereKeyNot($this->product->id)->orderBy('id')->firstOrFail();
        $this->list('MINE', ['customer_id' => $this->customer->id], '92');

        $all = $this->prices->forMany($this->customer, [$this->product, $second]);

        $this->assertSame('92.0000', $all[$this->product->id]->price);
        $this->assertSame(SalesPrice::STANDARD, $all[$second->id]->source);
        $this->assertSame(bcadd((string) $second->sale_price, '0', 4), $all[$second->id]->price);
    }

    private function assertResolved(string $price, string $source): void
    {
        $p = $this->prices->for($this->customer, $this->product);
        $this->assertSame([$price, $source], [$p->price, $p->source]);
    }

    /** @param  array<string, mixed>  $target */
    private function list(string $code, array $target, ?string $price, mixed $from = null): PriceList
    {
        $list = PriceList::query()->create(['code' => 'T'.$code, 'name_en' => 'List '.$code, 'is_active' => true] + $target);

        if ($price !== null) {
            $this->item($list, $price, $from ?? now()->subDay());
        }

        return $list;
    }

    private function item(PriceList $list, string $price, mixed $from, mixed $to = null): PriceListItem
    {
        return PriceListItem::query()->create([
            'price_list_id' => $list->id,
            'product_id' => $this->product->id,
            'price' => $price,
            'valid_from' => $from->toDateString(),
            'valid_to' => $to?->toDateString(),
        ]);
    }
}
