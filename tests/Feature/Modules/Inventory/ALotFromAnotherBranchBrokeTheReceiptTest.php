<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BatchService;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অন্য শাখার লট-নম্বরে মাল এলে গ্রহণ ৫০০ দিত — মজুদ লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ [[BatchService::receive()]] লট খুঁজত দেখার দেয়ালসহ। যে লটের সব চলাচল অন্য শাখার গুদামে, সেটা আড়ালে পড়ত; খোঁজা
 * কিছু না পেয়ে নতুন সারি বসাত, আর (কোম্পানি · পণ্য · লট) অনন্য-সূচক ৫০০ দিত। ⓘ ক্রয়ের গ্রহণ, খোলা মজুদ, গণনা আর
 * মান-পরীক্ষা — মাল ঢোকার প্রতিটা পথ এখান দিয়ে যায়, তাই একই কোম্পানির দুই শাখায় একই ব্যাচ এলেই কাজ বন্ধ।
 */
final class ALotFromAnotherBranchBrokeTheReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_same_lot_number_received_in_another_branch_is_the_same_lot(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $home = $company->defaultBranch();
        CompanyContext::set($company->id, $home->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $other = Branch::query()->where('company_id', $company->id)->whereKeyNot($home->id)->first()
            ?? Branch::query()->create(['company_id' => $company->id, 'code' => 'LOTB', 'name_en' => 'Other branch', 'is_active' => true]);

        $far = Warehouse::acrossWarehouses()->create(['code' => 'LOT-FAR', 'name_en' => 'Far', 'is_active' => true, 'branch_id' => $other->id]);
        $product = Product::query()->orderBy('id')->firstOrFail();

        // ⓘ লটটা আগে অন্য শাখার গুদামে এসেছে — সব চলাচল সেখানেই
        $lot = app(BatchService::class)->receive($product, 'LOT-SHARED');
        app(StockService::class)->move(product: $product, warehouse: $far, sourceType: 'test.in', sourceId: 1, floor: '5', batch: $lot);

        // ⭐ এখন হেডারে কেবল নিজের শাখা — লটটা দেয়ালের আড়ালে
        $owner->forceFill(['view_all_branches' => false])->save();
        app(\App\Core\Services\DataScope::class)->forget();
        $this->assertNull(Batch::query()->find($lot->id), 'পরীক্ষার পূর্বশর্ত ভাঙা: লটটা দেয়ালের আড়ালে নেই, দাবিটা কিছু মাপছে না।');

        $again = app(BatchService::class)->receive($product, 'LOT-SHARED');

        $this->assertSame((int) $lot->id, (int) $again->id, 'একই লট-নম্বরে নতুন লট খুলল, বা অনন্য-সূচকে আটকাল।');
        $this->assertSame(1, Batch::acrossWarehouses()->where('product_id', $product->id)->where('batch_no', 'LOT-SHARED')->count());
    }
}
