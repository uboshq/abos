<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ লেবেল ছাপায় পণ্যের সংখ্যার সীমা ছিল না (পুরো-ERP অডিট, মজুদ ছ৫; মালিকের "সব খোলা ভুল", ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ সীমা কেবল কপিতে (২০০) — হাজার পণ্য × ২০০ এক অনুরোধে লাখ লেবেলের PDF। এখন সর্বোচ্চ ৫০০ পণ্য আর মোট ২,০০০ লেবেল;
 * তার ভেতরে আগের মতোই ছাপে।
 */
final class ALabelRunHasALimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_label_run_stops_at_the_product_and_label_limits_and_prints_inside_them(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $ids = Product::query()->orderBy('id')->limit(5)->pluck('id')->all();
        $this->assertCount(5, $ids, 'প্রস্তুতিটাই ভুল — ডেমোতে পাঁচটা পণ্য নেই।');

        // ⛔ ৫০১টা পণ্য — পণ্যের সীমা
        $this->get(route('inventory.label.print', ['products' => range(1, 501)]))->assertSessionHasErrors('products');

        // ⛔ ১১ × ২০০ = ২,২০০ — মোট লেবেলের সীমা (একই পণ্য কয়েকবার বাছা যায়, প্রতিবার আলাদা ঘর)
        $eleven = array_merge($ids, $ids, [$ids[0]]);
        $this->get(route('inventory.label.print', ['products' => $eleven, 'copies' => 200]))->assertSessionHasErrors('copies');

        // ⓘ সীমার ভেতরে আগের মতো ছাপা
        $this->get(route('inventory.label.print', ['products' => $ids, 'copies' => 2]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
