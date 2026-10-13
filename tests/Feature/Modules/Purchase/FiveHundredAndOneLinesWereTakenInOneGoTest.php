<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⓘ এক অনুরোধে সীমাহীন লাইন (fe, ১৩ অক্টোবর ২০২৬; Purchase-এর সব ফর্মের `lines`)।
 *
 * ⚠️ `lines` কেবল `array|min:1` ছিল — হাজার হাজার লাইনের একটা অনুরোধ এক লেনদেনে প্রতিটার জন্য পণ্য খুঁজে লিখত, তালা ধরে রেখে।
 * ⭐ এখন সর্বোচ্চ ৫০০ লাইন (দরপত্রে সর্বোচ্চ ১০০ সরবরাহকারী); বেশি হলে ফর্মেই থামে, কিছুই লেখা হয় না।
 */
final class FiveHundredAndOneLinesWereTakenInOneGoTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_with_more_than_five_hundred_lines_is_refused_at_the_form(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $product = Product::query()->orderBy('id')->value('id');
        $lines = array_fill(0, 501, ['product_id' => $product, 'ordered_qty' => '1', 'rate' => '10']);

        $this->from(route('purchase.order.create'))->post(route('purchase.order.store'), [
            'supplier_id' => Supplier::query()->orderBy('id')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'lines' => $lines,
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, PurchaseOrder::query()->count(), '⛔ ৫০১ লাইনের আদেশ লেখা হলো।');
    }
}
