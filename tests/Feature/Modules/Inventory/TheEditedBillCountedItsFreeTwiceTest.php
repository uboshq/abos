<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\FreeRatio;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সম্পাদিত বিলের ফ্রি দুইবার গোনা হত — Inventory অডিট ম৯, ৫ অক্টোবর ২০২৬।
 *
 * ⛔ লটে কত দামি আর কত ফ্রি এসেছিল তা গোনা হত কেবল ধনাত্মক সারি থেকে: বিল সম্পাদনায় আগের মাল উল্টে নতুন করে বসে, তাই
 * আগেরটা আর নতুনটা দুটোই গোনা হত — ১০০ দামি + ১০ ফ্রি, সম্পাদনায় ফ্রি ৫: গোনা ২০০ আর ১৫, অর্থাৎ ৭.৫%, আসলে ৫%।
 * কাউন্টার তখন অনুপাতের বেশি ফ্রি দিত।
 * ⭐ এখন আসার উৎসগুলোর নিট — অপেক্ষার ঘর আর তাক মিলিয়ে: সম্পাদনার উল্টো সারি বাদ যায়, আর বসানো (অপেক্ষা → তাক)
 * নিজেই শূন্য, তাই দুইবার গোনা হয় না।
 */
final class TheEditedBillCountedItsFreeTwiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_edited_bill_counts_only_what_it_brought_in_the_end(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $product = Product::query()->create([
            'code' => 'M9-'.mb_substr(md5(microtime()), 0, 8), 'name_en' => 'Free ratio probe', 'name_bn' => 'ফ্রি-অনুপাতের নমুনা',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id, 'is_active' => true, 'track_batch' => true,
        ]);
        $lot = Batch::query()->create(['product_id' => $product->id, 'batch_no' => 'FR-1', 'expiry_date' => now()->addYear()->toDateString()]);
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $row = fn (string $source, array $changes) => StockMovement::query()->create([
            'company_id' => CompanyContext::id(), 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'batch_id' => $lot->id,
            'trx_date' => now()->toDateString(), 'source_type' => $source, 'source_id' => 77, ...$changes,
        ]);

        // ⓘ বিল: ১০০ দামি + ১০ ফ্রি; গুদামের লোক ৫০ দামি তাকে তুললেন
        $row('purchase_bill', ['unplaced_change' => '100']);
        $row('purchase_bill:free', ['unplaced_free_change' => '10']);
        $row('purchase_bill', ['unplaced_change' => '-50', 'floor_change' => '50']);

        // ⓘ সম্পাদনা: আগের সব উল্টে, ফ্রি ৫ করে আবার
        $row('purchase_bill:cancel', ['unplaced_change' => '-50', 'floor_change' => '-50']);
        $row('purchase_bill:free:cancel', ['unplaced_free_change' => '-10']);
        $row('purchase_bill', ['unplaced_change' => '100']);
        $row('purchase_bill:free', ['unplaced_free_change' => '5']);

        $one = app(FreeRatio::class)->arrivedIn($lot);
        $this->assertSame([0, 0], [bccomp('100', $one['paid'], 4), bccomp('5', $one['free'], 4)],
            '⛔ সম্পাদিত বিলের আগের মালও গোনা হয়েছে: দামি '.$one['paid'].', ফ্রি '.$one['free'].' (হওয়ার কথা ১০০ আর ৫)।');

        $many = app(FreeRatio::class)->arrivedInMany([$lot->id])[(string) $lot->id];
        $this->assertSame([0, 0], [bccomp('100', $many['paid'], 4), bccomp('5', $many['free'], 4)],
            '⛔ কাউন্টারের লট-তালিকার অনুপাতও আগের মাল গুনেছে।');

        // ⓘ মাল বসানো আসার আগের রসিদ — মাল সোজা তাকে উঠেছিল; তাকের অংশ না গুনলে এই লট "কিছুই আসেনি" দেখাত
        $old = Batch::query()->create(['product_id' => $product->id, 'batch_no' => 'FR-OLD', 'expiry_date' => now()->addYear()->toDateString()]);
        StockMovement::query()->create(['company_id' => CompanyContext::id(), 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'batch_id' => $old->id, 'trx_date' => now()->toDateString(), 'source_type' => 'purchase_receipt', 'source_id' => 78, 'floor_change' => '20']);
        StockMovement::query()->create(['company_id' => CompanyContext::id(), 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'batch_id' => $old->id, 'trx_date' => now()->toDateString(), 'source_type' => 'purchase_receipt:free', 'source_id' => 78, 'free_change' => '1']);

        $before = app(FreeRatio::class)->arrivedIn($old);
        $this->assertSame([0, 0], [bccomp('20', $before['paid'], 4), bccomp('1', $before['free'], 4)],
            '⛔ তাকে সোজা ওঠা পুরনো রসিদের মাল গোনা হয়নি: দামি '.$before['paid'].', ফ্রি '.$before['free'].'।');
    }
}
