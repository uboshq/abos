<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Http\Controllers\StockTransferController;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\StockTransferService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * গন্তব্যের নয় এমন কেউ বদলির মাল "পৌঁছেছে" লিখে দিতেন — মজুদ লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ গ্রহণের দরজা দেখত কেবল চাবি আর "দুইজন" সুইচ; বদলি দেখা যায় শাখা ধরে। একই শাখার গুদাম X-এ সীমিত কেরানি গুদাম Y-এর
 * মাল গ্রহণ লিখতে পারতেন — যে মাল তিনি দেখেননি। ⓘ দাবি দরজা ধরে ডাকে (মধ্যস্থ চাবি-পাহারা বাদ দিয়ে), কারণ প্রশ্নটা
 * চাবির নয়, জায়গার।
 */
final class TheTransferWasReceivedBySomeoneElsewhereTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_clerk_limited_to_the_source_cannot_receive_but_the_destination_can(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $branch = $company->defaultBranch();
        CompanyContext::set($company->id, $branch->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $from = Warehouse::acrossWarehouses()->create(['code' => 'TR-FROM', 'name_en' => 'From', 'is_active' => true, 'branch_id' => $branch->id]);
        $to = Warehouse::acrossWarehouses()->create(['code' => 'TR-TO', 'name_en' => 'To', 'is_active' => true, 'branch_id' => $branch->id]);
        $product = Product::query()->orderBy('id')->firstOrFail();
        $product->forceFill(['track_batch' => false])->save();
        app(StockService::class)->move(product: $product->fresh(), warehouse: $from, sourceType: 'test.in', sourceId: 1, floor: '20');

        $transfers = app(StockTransferService::class);
        $transfer = $transfers->create(['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '5']]);
        $transfers->dispatch($transfer);

        $clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $limit = UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::WAREHOUSE, 'scope_id' => $from->id]);
        app(DataScope::class)->forget();
        $this->actingAs($clerk);

        try {
            app(StockTransferController::class)->receive(StockTransfer::query()->findOrFail($transfer->id));
            $this->fail('উৎস গুদামের কেরানি গন্তব্যের মাল গ্রহণ লিখে দিলেন।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(DocumentStatus::CONFIRMED, StockTransfer::query()->findOrFail($transfer->id)->status, 'থামা গ্রহণেও বদলি বন্ধ হলো।');

        // ⭐ গন্তব্যের কেরানি পারেন
        $limit->forceFill(['scope_id' => $to->id])->save();
        app(DataScope::class)->forget();
        app(StockTransferController::class)->receive(StockTransfer::query()->findOrFail($transfer->id));

        $this->assertSame(DocumentStatus::CLOSED, StockTransfer::query()->findOrFail($transfer->id)->status,
            'গন্তব্যের মানুষও গ্রহণ করতে পারলেন না।');
    }
}
