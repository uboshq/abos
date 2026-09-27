<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * কাউন্টারের পাতা প্রতিটা পণ্যের ক্রয়মূল্য বয়ে নিত — চাবি ছাড়াই।
 *
 * ── ⛔ কী ভাঙা ছিল (২৭ সেপ্টেম্বর ২০২৬, মার্জিনের কাজে ধরা পড়ল) ──────
 * [[DirectSaleController::catalogue()]] প্রতিটা পণ্যের `cost` (ক্রয়মূল্য)
 * পাতায় পাঠাত, আর দরজার চাবি কেবল `sales.challan.create`। ⚠️ অর্থাৎ
 * চালান বানাতে পারেন এমন যে কেউ পাতার উৎস খুলে গোটা তালিকার ক্রয়মূল্য
 * পড়তে পারতেন — যেটা `sales.cost.view`-এর পেছনে থাকার কথা।
 *
 * ── ⓘ কেন একই মানুষ দুইবার ─────────────────────────────────────────
 * দুইজন আলাদা মানুষ নিলে পার্থক্যটা সদস্যপদ বা অন্য কোনো চাবির কারণেও
 * হতে পারত। ⭐ একই মানুষ, একই পাতা — তফাত কেবল ঐ একটা চাবি।
 */
final class TheCounterPageCarriedThePurchasePriceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    public function test_the_purchase_price_reaches_the_counter_only_with_its_key(): void
    {
        /*
         * ⚠️ ক্রয়মূল্য শূন্য হলে "নেই" আর "০" আলাদা করা যেত না — তাই প্রতিটা
         * পণ্যে একটা চেনা, অশূন্য দাম।
         */
        Product::query()->update(['purchase_price' => '4321.5']);

        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id);
        $user->givePermissionTo(Permission::query()->where('name', '<>', 'sales.cost.view')->get());
        $user = $user->fresh();

        $this->assertFalse($user->can('sales.cost.view'), 'প্রস্তুতিটাই ভুল — চাবি না দিয়েও হাতে আছে।');

        // ── চাবি ছাড়া ────────────────────────────────────────────────
        $page = $this->counterAs($user);
        $products = collect($page->viewData('products'));

        $this->assertNotEmpty($products, 'প্রস্তুতিটাই ভুল — কাউন্টারের তালিকায় কোনো পণ্য নেই, তাই দাবিটা কিছুই দেখত না।');
        $this->assertFalse(
            $products->contains(fn (object $p) => property_exists($p, 'cost')),
            '⛔ চাবি ছাড়াই পণ্যের তালিকায় ক্রয়মূল্যের ঘর গেছে (০ হলেও ঘরটাই থাকার কথা নয়)।',
        );
        $this->assertFalse($this->carriesCost($page), '⛔ চাবি ছাড়াই পাতার উৎসে ক্রয়মূল্য আছে।');
        $this->assertStringNotContainsString('4321.5', (string) $page->getContent(), '⛔ চাবি ছাড়াই দামটা পাতার কোথাও আছে।');
        $page->assertDontSee(__('sales::field.costing'));

        // ── একই মানুষ, এবার চাবিসহ ─────────────────────────────────────
        $user->givePermissionTo('sales.cost.view');
        $user = $user->fresh();

        $page = $this->counterAs($user);
        $products = collect($page->viewData('products'));

        $this->assertTrue(
            $products->every(fn (object $p) => ($p->cost ?? null) === 4321.5),
            '⛔ চাবি থাকা সত্ত্বেও ক্রয়মূল্য পৌঁছায়নি — তাহলে উপরের "নেই" কিছুই প্রমাণ করে না।',
        );
        $this->assertTrue($this->carriesCost($page), '⛔ চাবিসহ পাতার উৎসে ক্রয়মূল্য নেই — উৎসের খোঁজটাই অন্ধ।');
        $this->assertStringContainsString('4321.5', (string) $page->getContent(), '⛔ চাবিসহ দামটা পাতায় নেই — দামের খোঁজটাই অন্ধ।');
        $page->assertSee(__('sales::field.costing'));
    }

    private function counterAs(User $user): TestResponse
    {
        return $this->actingAs($user)->get(route('sales.direct.create'))->assertOk();
    }

    /**
     * পাতার উৎসে `cost` ঘরটা আছে কি না — যে রূপেই লেখা হোক।
     *
     * ⓘ মেপে দেখা: কাউন্টারের `x-data` ঘরে `@js` উদ্ধৃতি চিহ্নকে `&quot;`
     * করে লেখে। ⚠️ প্রথম খসড়া কেবল সোজা উদ্ধৃতি খুঁজত, আর চাবিসহ দিকটা লাল
     * হয়ে ধরিয়ে দিল যে খোঁজটা অন্ধ — চাবি ছাড়া দিকটা তখন কিছু না দেখেই
     * সবুজ থাকত। ⓘ তাই দুই দিকই এই একই খোঁজ দিয়ে মাপা হয়।
     */
    private function carriesCost(TestResponse $page): bool
    {
        $html = (string) $page->getContent();

        return str_contains($html, '"cost":')
            || str_contains($html, '\u0022cost\u0022:')
            || str_contains($html, '&quot;cost&quot;:');
    }
}
