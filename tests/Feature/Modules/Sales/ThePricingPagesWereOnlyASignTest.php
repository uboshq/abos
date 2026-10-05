<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\Sales\Models\PriceListItem;
use App\Modules\Sales\Services\SalesPrice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "মূল্য নির্ধারণ"-এর চারটা পাতা কেবল সাইনবোর্ড ছিল — দর তালিকা, ধাপ ৩ (মালিক, ৫ অক্টোবর ২০২৬)।
 *
 * ⭐ প্রতিটা দরজা একই মানুষ দিয়ে দুইবার — চাবি ছাড়া বন্ধ আর কিছুই বদলায় না, চাবিতে খোলে আর ঠিক যা লেখার তাই বসে।
 */
final class ThePricingPagesWereOnlyASignTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $dealer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->dealer = Customer::query()->firstOrFail();
        $this->product = Product::query()->active()->orderBy('id')->firstOrFail();
        $this->product->forceFill(['sale_price' => '100'])->save();
    }

    /** ⭐ চারটা পাতাই আসল — দেখার চাবিতে খোলে, চাবি ছাড়া ৪০৩; মেনু আর "তৈরি হচ্ছে"-তে যায় না। */
    public function test_the_four_pages_open_on_the_view_key(): void
    {
        $clerk = $this->staff([]);

        foreach (['customer', 'tier', 'territory', 'all'] as $target) {
            $this->actingAs($clerk)->get(route('sales.price_book.index', ['target' => $target]))->assertForbidden();
        }

        $clerk = $this->staff(['sales.price_list.view'], $clerk);

        foreach (['customer', 'tier', 'territory', 'all'] as $target) {
            $this->actingAs($clerk)->get(route('sales.price_book.index', ['target' => $target]))
                ->assertOk()
                ->assertSee(__('sales::price_book.title_'.$target))
                ->assertDontSee('data-new-price-list', false);
        }

        // ⓘ মেনুর দামের সারিগুলো আসল পাতায় যায়, "তৈরি হচ্ছে"-তে নয়
        $this->actingAs($clerk)->get(route('sales.price_book.index'))
            ->assertSee(route('sales.price_book.index', ['target' => 'territory']), false)
            ->assertDontSee(route('sales.planned', ['screen' => 'pricing_customer']), false)
            ->assertDontSee(route('sales.planned', ['screen' => 'pricing_territory']), false);
    }

    /** ⛔ দেখার চাবিতে তালিকা বানানো যায় না — একই মানুষ, বানানোর চাবিতে বানায়, আর লক্ষ্যটা ঠিক একটা। */
    public function test_making_a_list_asks_for_the_manage_key_and_aims_at_one(): void
    {
        $clerk = $this->staff(['sales.price_list.view']);
        $body = ['code' => 'rahim', 'name_en' => 'Rahim special', 'target' => 'customer', 'target_id' => $this->dealer->id, 'is_active' => '1'];

        $this->actingAs($clerk)->post(route('sales.price_book.store'), $body)->assertForbidden();
        $this->assertSame(0, PriceList::query()->where('code', 'RAHIM')->count());

        $this->actingAs($clerk)->get(route('sales.price_book.create', ['target' => 'customer']))->assertForbidden();

        $clerk = $this->staff(['sales.price_list.manage'], $clerk);
        $this->actingAs($clerk)->get(route('sales.price_book.create', ['target' => 'customer']))->assertOk()
            ->assertSee('data-price-list-form', false)->assertSee($this->dealer->name());
        $this->actingAs($clerk)->post(route('sales.price_book.store'), $body)->assertSessionHasNoErrors()->assertRedirect();

        $list = PriceList::query()->where('code', 'RAHIM')->firstOrFail();
        $this->assertSame([(int) $this->dealer->id, null, null], [(int) $list->customer_id, $list->party_type_id, $list->location_id]);
        $this->assertSame(SalesPrice::CUSTOMER, SalesPrice::targetOf($list));
        $this->actingAs($clerk)->get(route('sales.price_book.edit', $list))->assertOk()->assertSee('RAHIM');
        $this->actingAs($clerk)->get(route('sales.price_book.index', ['target' => 'customer']))->assertOk()
            ->assertSee('Rahim special')->assertSee($this->dealer->name());

        // ⛔ লক্ষ্য ছাড়া গ্রাহকের তালিকা নয়, আর একই কোড দুইবার নয়
        $this->actingAs($clerk)->post(route('sales.price_book.store'), ['target_id' => null] + $body + ['code' => 'X1'])
            ->assertSessionHasErrors('target_id');
        $this->actingAs($clerk)->post(route('sales.price_book.store'), $body)->assertSessionHasErrors('code');
    }

    /** ⭐ গ্রিড থেকে দর বসে, খালি সারি বাদ; আবার বসালে একই সারি বদলায় আর ইতিহাসে আগে-পরে থাকে; দর খাটে। */
    public function test_the_grid_writes_prices_and_keeps_their_history(): void
    {
        $manager = $this->staff(['sales.price_list.view', 'sales.price_list.manage']);
        $list = PriceList::query()->create(['code' => 'GRID', 'name_en' => 'Grid', 'customer_id' => $this->dealer->id, 'is_active' => true]);
        $other = Product::query()->active()->whereKeyNot($this->product->id)->orderBy('id')->firstOrFail();
        $from = now()->subDay()->toDateString();

        $this->actingAs($manager)->post(route('sales.price_book.items.store', $list), [
            'valid_from' => $from,
            'rows' => [
                ['product_id' => $this->product->id, 'price' => '88'],
                ['product_id' => $other->id, 'price' => ''],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, PriceListItem::query()->where('price_list_id', $list->id)->count(), '⛔ খালি দরের সারিও বসেছে, বা কিছুই বসেনি।');
        $this->assertSame('88.0000', app(SalesPrice::class)->for($this->dealer, $this->product)->price);

        $this->actingAs($manager)->post(route('sales.price_book.items.store', $list), [
            'valid_from' => $from,
            'rows' => [['product_id' => $this->product->id, 'price' => '86.5']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, PriceListItem::query()->where('price_list_id', $list->id)->count(), '⛔ একই তারিখের দর নতুন সারি হলো, বদলাল না।');
        $this->assertSame('86.5000', app(SalesPrice::class)->for($this->dealer, $this->product)->price);

        $rows = $this->actingAs($manager)->get(route('sales.price_book.history', [$list, $this->product]))->assertOk()->viewData('rows');
        $change = $rows->firstWhere('field', 'price');
        $this->assertNotNull($change, '⛔ দর বদলাল, অথচ ইতিহাসে কিছু নেই।');
        $this->assertSame(['88.0000', '86.5000', $manager->name],
            [bcadd((string) $change->old, '0', 4), bcadd((string) $change->new, '0', 4), $change->user]);

        $this->actingAs($manager)->get(route('sales.price_book.show', $list))->assertOk()
            ->assertSee('data-price-grid', false)
            ->assertSee($this->product->name());
    }

    /** ⛔ দেখার চাবিতে গ্রিড নেই আর দর বসে না — একই মানুষ। */
    public function test_the_grid_is_shut_without_the_manage_key(): void
    {
        $viewer = $this->staff(['sales.price_list.view']);
        $list = PriceList::query()->create(['code' => 'SHUT', 'name_en' => 'Shut', 'is_active' => true]);

        $this->actingAs($viewer)->get(route('sales.price_book.show', $list))->assertOk()->assertDontSee('data-price-grid', false);
        $this->actingAs($viewer)->post(route('sales.price_book.items.store', $list), [
            'valid_from' => now()->toDateString(),
            'rows' => [['product_id' => $this->product->id, 'price' => '1']],
        ])->assertForbidden();

        $this->assertSame(0, PriceListItem::query()->count());
    }

    /** ⛔ ঋণাত্মক দর বা শুরুর আগে শেষ — কিছুই বসে না। */
    public function test_a_bad_grid_writes_nothing(): void
    {
        $manager = $this->staff(['sales.price_list.view', 'sales.price_list.manage']);
        $list = PriceList::query()->create(['code' => 'BAD', 'name_en' => 'Bad', 'is_active' => true]);

        $this->actingAs($manager)->post(route('sales.price_book.items.store', $list), [
            'valid_from' => now()->toDateString(),
            'rows' => [['product_id' => $this->product->id, 'price' => '-5']],
        ])->assertSessionHasErrors();

        $this->actingAs($manager)->post(route('sales.price_book.items.store', $list), [
            'valid_from' => now()->toDateString(),
            'valid_to' => now()->subDay()->toDateString(),
            'rows' => [['product_id' => $this->product->id, 'price' => '5']],
        ])->assertSessionHasErrors('valid_to');

        $this->assertSame(0, PriceListItem::query()->count());
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys, ?User $user = null): User
    {
        if ($user === null) {
            $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
            $user->companies()->attach($this->company->id, ['is_active' => true]);
        }

        foreach ($keys as $key) {
            CompanyContext::forCompany($this->company->id,
                fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
