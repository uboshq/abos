<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\ProductPackService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * প্যাকের পরিমাণে "1e3" লিখলে ৫০০ — মজুদ লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ `is_numeric('1e3')` সত্য, ফর্মের `numeric`-ও নেয়, কিন্তু `bccomp('1e3', …)` PHP 8-এ ValueError ছোড়ে — বাংলা বার্তার বদলে
 * ৫০০ (ইমপোর্ট বা API দিয়েও আসতে পারে)। ⓘ পয়েন্টের খাতা ([[LoyaltyLedger::redeem()]]) একই কারণে আগে থেকেই সাধারণ দশমিক চায়।
 */
final class APackOfOneEThreeGaveAFiveHundredTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pack_quantity_in_e_notation_is_refused_in_words(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $product = Product::query()->orderBy('id')->firstOrFail();
        $other = Unit::query()->whereKeyNot($product->unit_id)->orderBy('id')->firstOrFail();

        try {
            app(ProductPackService::class)->sync($product, [['unit_id' => $other->id, 'per_qty' => '1e3']]);
            $this->fail('"1e3" প্যাকের পরিমাণ হিসেবে বসে গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('packs.0.per_qty', $e->errors(), 'ভুলটা প্যাকের পরিমাণের ঘরে বলা হয়নি।');
        }
    }
}
