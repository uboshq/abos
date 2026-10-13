<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ পুরনো কপি থেকে বাতিল করা বিল কিছুই উল্টাত না (Purchase লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PurchaseBillService::cancel()]])।
 *
 * ⚠️ বাতিল তালা দিয়ে বিলটা আবার পড়ত, কিন্তু "পাকা ছিল কি না" দেখত হাতের পুরনো কপিতে। এক ট্যাবে খসড়া খোলা, আরেক ট্যাবে পাকা —
 * তারপর প্রথম ট্যাবে "বাতিল": বিল "বাতিল" হত, অথচ খাতা (২১১০, ২১৬০, ১১২০), মজুদ আর খরচের স্তর সব চালু থাকত।
 */
final class ABillCancelledFromAnOldCopyUndidNothingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bill_cancelled_from_a_draft_copy_after_it_was_confirmed_is_undone_in_full(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail();
        $before = app(StockService::class)->floorQty($product, $warehouse);

        $bills = app(PurchaseBillService::class);
        $draft = $bills->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->firstOrFail()->id, 'warehouse_id' => $warehouse->id,
                'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'STALE-1'],
            [['product_id' => $product->id, 'qty' => '10', 'rate' => '50']],
        );
        $this->assertSame(DocumentStatus::DRAFT, $draft->status);

        // ⓘ আরেক ট্যাবে পাকা — হাতের কপিটা এখনো "খসড়া"
        $bills->confirm(PurchaseBill::query()->findOrFail($draft->id));
        $this->assertSame(0, bccomp(bcadd($before, '10', 4), app(StockService::class)->floorQty($product, $warehouse), 4), 'দৃশ্যটাই বানানো যায়নি — মাল ঢোকেনি');

        $bills->cancel($draft, 'ভুল বিল');

        $this->assertSame(DocumentStatus::CANCELLED, $draft->fresh()->status);
        $this->assertTrue(DB::table('ledger_entries')->where('source_type', PurchaseBill::drillSourceType().':reversal')
            ->where('source_id', $draft->id)->exists(), '⛔ পুরনো কপি থেকে বাতিল — বিলের খাতা উল্টানো হয়নি।');
        $this->assertSame(0, bccomp($before, app(StockService::class)->floorQty($product, $warehouse), 4),
            '⛔ পুরনো কপি থেকে বাতিল — বিলের মাল তাকেই রয়ে গেল।');
    }
}
