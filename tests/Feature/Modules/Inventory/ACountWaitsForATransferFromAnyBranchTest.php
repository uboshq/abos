<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ পথে থাকা বদলির গণনা-পাহারা শাখার দেয়ালে আটকাত, আর গন্তব্যে পথে থাকা মাল গোনা যেত (পুরো-ERP অডিট, ৯ অক্টোবর ২০২৬,
 * মজুদের নতুন ⚠️ আর ⓘ)।
 *
 * ⓘ [[StockCountService]] পথে থাকা বদলি খুঁজত `StockTransfer::query()` দিয়ে — তাতে মানুষের শাখার দেয়াল, অথচ বদলি লেখা হয়
 * পাঠকের শাখায়। অন্য শাখার মানুষ পাঠালে এই গুদামের কেরানি বদলিটা দেখতেন না, আর ট্রাকের মাল আবার মিথ্যা ঘাটতি হয়ে বসত।
 * ⓘ গন্তব্যে মাল নেমে গেলেও গ্রহণ পর্যন্ত খাতায় নেই — তখন গুনলে মিথ্যা বাড়তি, মেনে নিলে পরে গ্রহণে দ্বিগুণ।
 */
final class ACountWaitsForATransferFromAnyBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_clerk_cannot_count_goods_another_branch_put_on_the_truck_or_brought_to_the_door(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $mine = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $theirs = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($company->id, $theirs->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $from = Warehouse::query()->create(['code' => 'CW-FROM', 'name_en' => 'Count from', 'is_active' => true, 'branch_id' => $mine->id]);
        $to = Warehouse::query()->create(['code' => 'CW-TO', 'name_en' => 'Count to', 'is_active' => true, 'branch_id' => $mine->id]);
        $rice = Product::query()->create(['code' => 'CW-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Count rice', 'name_bn' => 'গণনার চাল',
            'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true]);
        app(StockService::class)->move(product: $rice, warehouse: $from, sourceType: 'test.opening', sourceId: $rice->id, floor: '50');
        app(CostLayerService::class)->receive(product: $rice, qty: '50', unitCost: '10', sourceType: 'test.opening', sourceId: $rice->id);

        // ⓘ নেত্রকোনার মানুষ পাঠালেন — বদলি নেত্রকোনার শাখায় লেখা
        $transfers = app(StockTransferService::class);
        $transfer = $transfers->create(['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'trx_date' => now()->toDateString(),
            'branch_id' => $theirs->id], [['product_id' => $rice->id, 'qty' => '10']]);
        $transfers->dispatch($transfer);
        $this->assertSame($theirs->id, (int) $transfer->fresh()->branch_id, 'প্রস্তুতিটাই ভুল — বদলি অন্য শাখায় লেখা হয়নি।');

        // ⓘ ময়মনসিংহে সীমিত কেরানি — নেত্রকোনার কাগজ তাঁর দেখার বাইরে
        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mine->id]);
        app(DataScope::class)->forget();
        CompanyContext::set($company->id, $mine->id);
        $this->actingAs($clerk);

        $number = (string) $transfer->fresh()->document_no;

        $words = ['transfer' => $number, 'product' => $rice->name()];

        // ⛔ উৎসে — ট্রাকের মাল মিথ্যা ঘাটতি নয়
        $this->assertRefused(fn () => $this->countIn($from, $rice, '40'), __('inventory::validation.count_while_on_the_way', $words),
            '⛔ অন্য শাখার লেখা বদলির মাল উৎসে গোনা গেল — ১০-এর মিথ্যা ঘাটতি বসত।');

        // ⛔ গন্তব্যে — নেমে আসা মাল গ্রহণের আগে মিথ্যা বাড়তি নয়
        $this->assertRefused(fn () => $this->countIn($to, $rice, '10'), __('inventory::validation.count_while_arriving', $words),
            '⛔ গ্রহণের আগে গন্তব্যে গোনা গেল — ১০-এর মিথ্যা বাড়তি, পরে গ্রহণে দ্বিগুণ।');
    }

    private function assertRefused(callable $count, string $message, string $why): void
    {
        try {
            $count();
            $this->fail($why);
        } catch (ValidationException $e) {
            $this->assertContains($message, $e->validator->errors()->all(), $why);
        }
    }

    private function countIn(Warehouse $warehouse, Product $product, string $qty)
    {
        return app(StockCountService::class)->record(['warehouse_id' => $warehouse->id], [['product_id' => $product->id, 'counted_qty' => $qty]]);
    }
}
