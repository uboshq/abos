<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "মূল্য তালিকা" ছিল কেবল একটা সাইনবোর্ড — মালিকের নির্দেশ, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ⛔ বিক্রয়ের "মূল্য নির্ধারণ" ভাঁজের পাতাটা "তৈরি হচ্ছে" দেখাত। এখন সেটা আসল
 * পাতা: প্রতিটা পণ্যের বিক্রয়-দাম, সারি থেকে বদলানো, আর কে কবে কত থেকে বদলাল।
 *
 * ⭐ প্রতিটা দরজা একই মানুষ দিয়ে দুইবার — চাবি ছাড়া বন্ধ আর কিছুই বদলায় না,
 * চাবিতে খোলে আর ঠিক যা লেখার কথা তাই বসে ([[same-user-key-off-then-on]])।
 */
final class ThePriceListWasOnlyASignTest extends TestCase
{
    use RefreshDatabase;

    private User $clerk;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->clerk = User::factory()->create(['current_company_id' => $company->id]);
        $this->clerk->companies()->attach($company->id, ['is_active' => true]);

        $this->product = Product::query()->active()->orderBy('id')->firstOrFail();
    }

    /** ⭐ পাতাটা আসল — দেখার চাবিতে খোলে, আর পণ্যের নামটাই লিংক। */
    public function test_the_list_opens_on_its_key_and_links_each_product(): void
    {
        $this->actingAs($this->clerk)->get(route('sales.price_list.index'))->assertForbidden();

        $this->clerk->givePermissionTo('sales.order.view');

        $this->actingAs($this->clerk->fresh())->get(route('sales.price_list.index'))
            ->assertOk()
            ->assertSee(route('inventory.product.show', $this->product), false);
    }

    /**
     * ⛔ দেখার চাবিতে দাম বদলায় না — আর পণ্য সম্পাদনার চাবিতে বদলায়, ইতিহাসসহ।
     */
    public function test_changing_a_price_asks_for_the_product_update_key_and_leaves_history(): void
    {
        $this->clerk->givePermissionTo('sales.order.view');
        $before = (string) $this->product->fresh()->sale_price;
        $after = bcadd($before, '12.5', 4);

        $this->actingAs($this->clerk->fresh())
            ->put(route('sales.price_list.update', $this->product), ['sale_price' => $after])
            ->assertForbidden();
        $this->assertSame($before, (string) $this->product->fresh()->sale_price, '⛔ ৪০৩ ফিরেছে, তবু দাম বদলে গেছে।');

        $this->clerk->givePermissionTo('inventory.product.update');

        $this->actingAs($this->clerk->fresh())
            ->put(route('sales.price_list.update', $this->product), ['sale_price' => $after])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($after, (string) $this->product->fresh()->sale_price, '⛔ দরজা খুলেছে, কিন্তু দাম বদলায়নি।');

        $history = $this->actingAs($this->clerk->fresh())->get(route('sales.price_list.history', $this->product))
            ->assertOk()->viewData('rows');

        $last = $history->first();
        $this->assertNotNull($last, '⛔ দাম বদলাল, অথচ ইতিহাসে কিছু নেই।');
        $this->assertSame([$before, $after, $this->clerk->name],
            [bcadd((string) $last->old, '0', 4), bcadd((string) $last->new, '0', 4), $last->user],
            '⛔ ইতিহাস ভুল বলছে কে, আগে কত, পরে কত।');
    }

    /** ⛔ সংখ্যা নয় বা ঋণাত্মক দাম বসে না। */
    public function test_a_price_that_is_not_a_price_is_refused(): void
    {
        $this->clerk->givePermissionTo(['sales.order.view', 'inventory.product.update']);
        $before = (string) $this->product->fresh()->sale_price;

        foreach (['abc', '-1'] as $bad) {
            $this->actingAs($this->clerk->fresh())
                ->put(route('sales.price_list.update', $this->product), ['sale_price' => $bad])
                ->assertSessionHasErrors('sale_price');
        }

        $this->assertSame($before, (string) $this->product->fresh()->sale_price);
    }

    /**
     * ⛔ ক্রয়মূল্য কেবল তার **ঘোষিত** চাবিতে — একই মানুষ, চাবি বন্ধ থেকে চালু।
     *
     * ⓘ ঘরটার চাবি Inventory ঘোষণা করেছে (`inventory.cost.view`, [[FieldSecurity]])।
     * প্রথম সংস্করণ `sales.cost.view` দেখত — তাই যাঁর বিক্রয়ের খরচ দেখার চাবি
     * আছে কিন্তু ক্রয়মূল্যের নেই, তিনিও এখানে ক্রয়মূল্য দেখতেন (২৮ সেপ্টেম্বর
     * ২০২৬, Architecture-এর NoSensitiveFieldIsPrintedInTheOpenTest ধরেছে)।
     */
    public function test_the_purchase_price_shows_only_on_its_key(): void
    {
        $this->clerk->givePermissionTo('sales.order.view', 'sales.cost.view');

        $this->actingAs($this->clerk->fresh())->get(route('sales.price_list.index'))
            ->assertOk()
            ->assertDontSee(__('sales::price_list.cost'));

        $this->clerk->givePermissionTo('inventory.cost.view');

        $this->actingAs($this->clerk->fresh())->get(route('sales.price_list.index'))
            ->assertSee(__('sales::price_list.cost'));
    }

    /** ⭐ মেনুর সারি আসল পাতায় যায়, আর পুরনো সাইনবোর্ডের ঠিকানা আর খোলে না। */
    public function test_the_menu_points_at_the_real_page_and_the_sign_is_gone(): void
    {
        $this->clerk->givePermissionTo('sales.order.view');

        $this->actingAs($this->clerk->fresh())
            ->get(route('sales.planned', ['screen' => 'pricing_lists']))
            ->assertNotFound();
    }
}
