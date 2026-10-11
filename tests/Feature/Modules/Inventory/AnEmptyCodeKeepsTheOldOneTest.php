<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ পণ্যের সম্পাদনায় কোড ফাঁকা রাখলে পাতা ৫০০ (পুরো-ERP অডিট, মজুদ ছ১৫; মালিকের "সব খোলা ভুল", ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ নিয়মে কোড `nullable` (নতুন পণ্যে সিরিজ থেকে আসে), কিন্তু [[ProductService::update()]] ফাঁকা কোড সোজা লিখত — ঘরটা `NOT NULL`।
 * এখন ফাঁকা মানে "যেমন আছে": পুরনো কোড কাগজে ছাপা হয়ে গেছে, সেটাই থাকে।
 */
final class AnEmptyCodeKeepsTheOldOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_product_with_its_code_cleared_keeps_the_old_code(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $product = Product::query()->create(['code' => 'EC-'.mb_substr(md5(microtime()), 0, 6), 'name_en' => 'Empty code probe',
            'name_bn' => 'ফাঁকা কোডের নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true,
            'sale_price' => '120', 'purchase_price' => '100']);
        $code = $product->code;

        $this->from(route('inventory.product.edit', $product))
            ->put(route('inventory.product.update', $product), [
                'code' => '',
                'name_en' => 'Empty code probe, renamed',
                'name_bn' => $product->name_bn,
                'unit_id' => $product->unit_id,
                'sale_price' => '120',
                'purchase_price' => '100',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $fresh = $product->fresh();
        $this->assertSame($code, $fresh->code, '⛔ ফাঁকা কোড পুরনো কোড মুছে দিল।');
        $this->assertSame('Empty code probe, renamed', $fresh->name_en, '⛔ সংরক্ষণই হল না।');
    }
}
