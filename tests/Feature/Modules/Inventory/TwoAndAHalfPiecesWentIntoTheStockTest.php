<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নিজের এককে লেখা ভাঙা পরিমাণ চলে — ম১৯ ফেরানো, ৬ অক্টোবর ২০২৬।
 *
 * ⛔ ম১৯ (023caadf) নিজের এককেও "ভাঙা যায় না" যাচাই বসিয়েছিল। কিন্তু `allows_fraction` প্রতিটা এককে ডিফল্টে মিথ্যা, আর
 * কোথাও কেউ সেটা সত্যি বসায় না — কেজি আর লিটারও তাই "ভাঙা যায় না"। পুরো দৌড়ে ধরা পড়ল: আড়াই কেজি চিনি কেনা আর পাঁচ
 * লিটার তেলের ভাঙা বিক্রি থামল; লাইভেও থামত।
 * ⭐ এই দাবি সেটাই আটকায়: ভগ্নাংশ-বন্ধ এককের পণ্যেও নিজের এককে ২.৫ বিলে বসে। এককগুলো ঠিকভাবে বসানোর পরে নিয়মটা
 * ফিরলে এই দাবিও বদলাতে হবে — জেনে-বুঝে।
 */
final class TwoAndAHalfPiecesWentIntoTheStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fraction_in_the_products_own_unit_still_goes_on_a_bill(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $unit = Unit::query()->create(['code' => 'M19U', 'name_en' => 'kg (unset)', 'name_bn' => 'কেজি', 'factor' => '1',
            'allows_fraction' => false, 'is_active' => true]);
        $sugar = Product::query()->create(['code' => 'M19-SUGAR', 'name_en' => 'Loose sugar', 'name_bn' => 'খোলা চিনি',
            'unit_id' => $unit->id, 'sale_price' => '100', 'is_active' => true]);

        $this->assertSame('2.5', app(PackConversion::class)->toStockQty($sugar, '2.5'), '⛔ নিজের এককে আড়াই কেজি থামল।');

        $bill = app(SalesInvoiceService::class)->create(
            ['customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->orderBy('id')->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $sugar->id, 'qty' => '2.5', 'rate' => '100']],
        );

        $this->assertSame(0, bccomp((string) $bill->lines->first()->qty, '2.5', 4), '⛔ আড়াই কেজির বিল বসল না।');
    }
}
