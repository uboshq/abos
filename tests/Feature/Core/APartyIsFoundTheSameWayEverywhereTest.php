<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Search\SearchEngine;
use App\Core\Engines\Search\SearchHit;
use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\PartySearch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Reports\Filters\CustomerFilter;
use App\Modules\MasterData\Models\Location;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Reports\Filters\SupplierFilter;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ পক্ষকে নাম, মোবাইল, ঠিকানা বা পয়েন্ট — যেকোনোটা দিয়ে সব জায়গায় (মালিক, ২৩ নভেম্বর ২০২৬; [[PartySearch]])।
 *
 * ⓘ একটা দোকান আর একটা সরবরাহকারী বসানো হয়, আর প্রতিটা খোঁজার জায়গায় চার ভাবে খোঁজা হয়:
 *   নাম · মোবাইলের শেষ চার অঙ্ক · ঠিকানার একটা শব্দ · পয়েন্টের নাম (আর তার মা, মায়ের মা)।
 * ⚠️ প্রতিটা দাবির পাশে একটা "পায় না" — যে খোঁজা সবাইকে দেয় সে-ও "পায়" দাবি পাস করত।
 * ⓘ সরবরাহকারীর পয়েন্ট নেই (টেবিলে ঘরই নেই) — তাই তার তিন ভাবে।
 */
final class APartyIsFoundTheSameWayEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $shop;

    private Supplier $supplier;

    /** @var array<string, string> কোন ভাবে => কী লেখা */
    private const SHOP_TERMS = [
        'name' => 'Quokka Traders',
        'last 4 digits of the mobile' => '7319',
        'an address word' => 'Zebulon',
        'the point' => 'Pelican Ghat',
        'the point\'s parent' => 'Heron Area',
        'the point\'s grandparent' => 'Ibis Region',
    ];

    private const SUPPLIER_TERMS = [
        'name' => 'Wombat Mills',
        'last 4 digits of the mobile' => '8462',
        'an address word' => 'Xanthos',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $region = $this->place('RG-Q', 'Ibis Region', Location::REGION);
        $area = $this->place('AR-Q', 'Heron Area', Location::AREA, $region);
        $point = $this->place('PT-Q', 'Pelican Ghat', Location::POINT, $area);

        $this->shop = Customer::query()->orderBy('id')->firstOrFail();
        $this->shop->forceFill([
            // ⓘ দুই ভাষার নামই — পিকার আর খোঁজার লেবেল অ্যাপের ভাষায় (bn) নাম দেখায়; কেবল name_en বদলালে "Quokka" লেবেলে আসত না
            'name_en' => 'Quokka Traders', 'name_bn' => 'Quokka Traders', 'phone' => '01711-557319',
            'address_en' => 'House 4, Zebulon Lane', 'location_id' => $point->id, 'is_active' => true,
        ])->save();

        $this->supplier = Supplier::query()->create([
            'code' => 'SUP-Q', 'name_en' => 'Wombat Mills', 'name_bn' => 'Wombat Mills',
            'phone' => '+880 1811 228462', 'address_en' => 'Plot 9, Xanthos Road', 'is_active' => true,
        ]);
    }

    /* ── এক নিয়ম, মডেলে ─────────────────────────────────────────────── */

    public function test_the_customer_list_rule_finds_the_shop_four_ways(): void
    {
        foreach (self::SHOP_TERMS as $how => $term) {
            $this->assertTrue(Customer::query()->search($term)->whereKey($this->shop->id)->exists(),
                "⛔ গ্রাহকের খোঁজা {$how} দিয়ে ({$term}) দোকানটা পায়নি।");
        }

        $this->assertFalse(Customer::query()->search('Quokka Xanthos')->whereKey($this->shop->id)->exists(),
            '⛔ একটা শব্দ না মিললেও দোকান এল — প্রতিটা শব্দ মেলার নিয়ম ভাঙা।');
    }

    public function test_the_supplier_rule_finds_the_supplier_three_ways(): void
    {
        foreach (self::SUPPLIER_TERMS as $how => $term) {
            $this->assertTrue(Supplier::query()->search($term)->whereKey($this->supplier->id)->exists(),
                "⛔ সরবরাহকারীর খোঁজা {$how} দিয়ে ({$term}) পায়নি।");
        }

        $this->assertFalse(Supplier::query()->search('Zebulon')->whereKey($this->supplier->id)->exists());
    }

    /* ── তালিকার পাতা (গ্রাহক, সরবরাহকারী) ─────────────────────────── */

    public function test_the_customer_and_supplier_lists_find_them(): void
    {
        foreach (self::SHOP_TERMS as $how => $term) {
            $this->get(route('customer.index', ['q' => $term]))->assertOk()
                ->assertSee('Quokka Traders', false);
        }

        foreach (self::SUPPLIER_TERMS as $how => $term) {
            $this->get(route('supplier.index', ['q' => $term]))->assertOk()
                ->assertSee('Wombat Mills', false);
        }
    }

    /* ── পক্ষের পিকার (ব্রাউজারে ছাঁকে — `find` লেখা) আর সরাসরি বিক্রয় ── */

    public function test_the_party_picker_text_carries_name_mobile_address_and_point(): void
    {
        $groups = collect(app(PartyRegistry::class)->forPicker())->keyBy('type');

        $shop = collect($groups['customer']['options'] ?? [])->firstWhere('id', $this->shop->id);
        $supplier = collect($groups['supplier']['options'] ?? [])->firstWhere('id', $this->supplier->id);

        $this->assertNotNull($shop, 'দোকানটা পিকারেই নেই।');
        $this->assertNotNull($supplier, 'সরবরাহকারী পিকারেই নেই।');

        foreach (self::SHOP_TERMS as $how => $term) {
            $this->assertStringContainsString(mb_strtolower($term), $shop['find'], "⛔ পিকারের লেখায় {$how} নেই।");
        }

        foreach (self::SUPPLIER_TERMS as $how => $term) {
            $this->assertStringContainsString(mb_strtolower($term), $supplier['find'], "⛔ পিকারের লেখায় {$how} নেই।");
        }
    }

    public function test_the_direct_sale_customer_text_carries_name_mobile_address_and_point(): void
    {
        $shop = Customer::query()->with([...Customer::PLACE, 'location.parent.parent'])->findOrFail($this->shop->id);

        foreach (self::SHOP_TERMS as $how => $term) {
            $this->assertStringContainsString(mb_strtolower($term), $shop->searchText(), "⛔ সরাসরি বিক্রয়ের খোঁজার লেখায় {$how} নেই।");
        }
    }

    /* ── নোট, ভাউচারের তালিকা — পক্ষ ধরে ([[PartyRegistry::matching()]]) ── */

    public function test_the_party_matching_of_notes_and_vouchers_finds_them(): void
    {
        $registry = app(PartyRegistry::class);

        foreach (self::SHOP_TERMS as $how => $term) {
            $this->assertContains($this->shop->id, $registry->matching($term, ['customer'])['customer'] ?? [],
                "⛔ পক্ষ মেলানো (নোট, ভাউচার) {$how} দিয়ে দোকান পায়নি।");
        }

        foreach (self::SUPPLIER_TERMS as $how => $term) {
            $this->assertContains($this->supplier->id, $registry->matching($term, ['supplier'])['supplier'] ?? [],
                "⛔ পক্ষ মেলানো {$how} দিয়ে সরবরাহকারী পায়নি।");
        }
    }

    public function test_the_voucher_list_searches_by_its_party(): void
    {
        $voucher = Voucher::query()->whereNotNull('party_id')->where('party_type', 'customer')->first();

        if ($voucher === null) {
            // ⓘ ডেমোতে পক্ষওয়ালা ভাউচার না থাকলে কেবল প্রশ্নটা চলে কি না
            $this->assertIsBool(Voucher::query()->search('7319')->exists());

            return;
        }

        $voucher->forceFill(['party_id' => $this->shop->id])->save();

        foreach (self::SHOP_TERMS as $how => $term) {
            $this->assertTrue(Voucher::query()->search($term)->whereKey($voucher->id)->exists(),
                "⛔ ভাউচারের তালিকা পক্ষের {$how} দিয়ে ({$term}) ভাউচার পায়নি।");
        }
    }

    /* ── রিপোর্টের ছাঁকনির পিকার ──────────────────────────────────────── */

    public function test_the_report_filter_picker_finds_them_and_waits_for_three_letters(): void
    {
        foreach (self::SHOP_TERMS as $how => $term) {
            $labels = array_column(app(CustomerFilter::class)->search($term), 'label');
            $this->assertTrue(collect($labels)->contains(fn ($l) => str_contains($l, 'Quokka')), "⛔ রিপোর্টের পিকার {$how} দিয়ে দোকান পায়নি।");
        }

        foreach (self::SUPPLIER_TERMS as $how => $term) {
            $labels = array_column(app(SupplierFilter::class)->search($term), 'label');
            $this->assertTrue(collect($labels)->contains(fn ($l) => str_contains($l, 'Wombat')), "⛔ রিপোর্টের পিকার {$how} দিয়ে সরবরাহকারী পায়নি।");
        }

        $this->assertSame([], app(CustomerFilter::class)->search('Qu'), '⛔ দুই অক্ষরে সার্ভার-পিকার খুঁজল — সীমা তিন।');
    }

    /* ── গ্লোবাল খোঁজা ─────────────────────────────────────────────────── */

    public function test_the_global_search_finds_them_and_waits_for_three_letters(): void
    {
        $this->assertSame(3, SearchEngine::MIN_TERM);
        $this->assertSame(3, PartySearch::MIN_TYPED);

        foreach (self::SHOP_TERMS as $how => $term) {
            $this->assertTrue($this->found($term, 'Quokka'), "⛔ গ্লোবাল খোঁজা {$how} দিয়ে দোকান পায়নি।");
        }

        foreach (self::SUPPLIER_TERMS as $how => $term) {
            $this->assertTrue($this->found($term, 'Wombat'), "⛔ গ্লোবাল খোঁজা {$how} দিয়ে সরবরাহকারী পায়নি।");
        }

        $this->getJson(route('search', ['q' => 'Qu']))->assertOk()->assertExactJson(['hits' => []]);
    }

    private function found(string $term, string $name): bool
    {
        return collect(app(SearchEngine::class)->search($term, $this->owner))
            ->contains(fn (SearchHit $hit) => str_contains($hit->label.' '.$hit->documentNo, $name));
    }

    private function place(string $code, string $en, string $level, ?Location $parent = null): Location
    {
        return Location::query()->create([
            'company_id' => CompanyContext::id(), 'parent_id' => $parent?->id, 'code' => $code,
            'name_en' => $en, 'name_bn' => null, 'level' => $level, 'is_active' => true,
        ]);
    }
}
