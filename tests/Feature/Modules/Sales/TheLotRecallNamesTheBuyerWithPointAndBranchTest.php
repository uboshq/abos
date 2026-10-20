<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ রিকলের ক্রেতা "নাম · পয়েন্ট · শাখা" — মালিকের স্থায়ী নিয়ম, ১৯ অক্টোবর ২০২৬ ([[LotTraceController]], [[PartyRegistry::labelsOf()]])।
 *
 * ⛔ আগে রিকলের সারিতে কেবল খালি নাম — একই নামের দুই দোকান কোনটা, ফোন করার আগে বোঝা যেত না।
 * ⓘ নামটা পক্ষের এক উৎস থেকে, পাতাপ্রতি একবারে: পয়েন্ট আর শাখা যোগ হয় সেখানেই, তাই পাতাটা ঐ উৎসের লেখাই দেখায়।
 */
final class TheLotRecallNamesTheBuyerWithPointAndBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_recall_row_reads_name_point_and_branch_from_the_shared_helper(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['locale' => 'en'])->save();
        $this->actingAs($owner->fresh());

        // ⓘ ক্রেতাকে একটা পয়েন্টে বসানো — পয়েন্টের নাম পাতায় না এলে দাবিটা ধরবে
        $point = Location::query()->where('level', Location::POINT)->orderBy('id')->firstOrFail();
        $buyer = Customer::query()->orderBy('id')->firstOrFail();
        $buyer->forceFill(['location_id' => $point->id])->save();
        $buyer = $buyer->fresh(['location.parent', 'branch']);
        $this->assertSame(trim((string) ($point->name_bn ?: $point->name_en)), $buyer->pointName(), 'দৃশ্যটাই বানানো যায়নি — ক্রেতা পয়েন্টে বসেননি');

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->firstOrFail();
        $product->forceFill(['track_batch' => true])->save();
        $batch = Batch::query()->create(['product_id' => $product->id, 'batch_no' => 'NAME-RECALL1', 'expiry_date' => now()->addMonths(9)->toDateString()]);
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test_opening', sourceId: $batch->id, floor: '100', batch: $batch);

        $challans = app(DeliveryChallanService::class);
        $challans->confirm($challans->create(
            ['customer_id' => $buyer->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'delivered_qty' => '2', 'rate' => '100']],
        ));

        $label = app(PartyRegistry::class)->labelsOf([['customer', (int) $buyer->id]])['customer:'.$buyer->id] ?? null;
        $this->assertNotNull($label, 'দৃশ্যটাই বানানো যায়নি — পক্ষের উৎস ক্রেতাকে চেনে না');
        $this->assertStringStartsWith($buyer->name().' · '.$buyer->pointName(), $label, 'পক্ষের উৎস নামের পাশে পয়েন্ট দেয় না');

        $page = $this->get(route('sales.lot.trace', ['batch' => $batch->id]))->assertOk();

        // ⭐ সারিতে পুরো লেখাটা — নাম · পয়েন্ট (· শাখা, পক্ষের উৎস যখন শাখা দেয়), খালি নাম নয়
        $page->assertSee($label);
        $page->assertSee($buyer->name().' · '.$buyer->pointName());

        $branch = trim((string) $buyer->branch?->name());
        if ($branch !== '' && str_contains($label, $branch)) {
            $page->assertSee($buyer->name().' · '.$buyer->pointName().' · '.$branch);
        }
    }
}
