<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * আদেশের দর বিলের সামনে কেউ ধরত না — মালিকের টাকা আসা-যাওয়ার আন্তর্জাতিক পরিকল্পনা, ধাপ খ ১০ (3-Way Match), ৭ অক্টোবর ২০২৬।
 *
 * ⓘ পরিমাণ তিন দিকেই আগে থেকে মেলে, দাম বিল বনাম চালান; ⛔ কিন্তু বিলের দর আদেশের দরের সাথে নয়। ⭐ এখন সুইচ
 * `purchase.block_order_price_mismatch` চালু থাকলে থামে, বন্ধ থাকলে মিলের ফলে "exception" দাগ। আজকের কোম্পানিগুলোতে
 * মাইগ্রেশনে বন্ধ; নতুন কোম্পানিতে চালু।
 */
final class TheOrderRateWasNeverHeldAgainstTheBillTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->firstOrFail();
    }

    public function test_with_the_switch_on_a_bill_above_the_order_rate_stops(): void
    {
        $this->assertTrue((bool) app(SettingsService::class)->get('purchase.block_order_price_mismatch', true), 'প্রস্তুতি: নতুন কোম্পানিতে সুইচ চালু নয়।');
        $order = $this->order('100');

        try {
            $this->bill($order, '110');
            $this->fail('⛔ আদেশের ১০০-এর জায়গায় ১১০-এর বিল পাশ হলো।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
            $this->assertStringContainsString('110', implode(' ', $e->validator->errors()->all()));
        }

        $same = $this->bill($this->order('100'), '100');
        $this->assertSame(DocumentStatus::CONFIRMED, $same->status, '⛔ একই দরের বিলও থামল।');
    }

    public function test_with_the_switch_off_the_bill_goes_through_marked_as_an_exception(): void
    {
        app(SettingsService::class)->set('purchase.block_order_price_mismatch', false);

        $bill = $this->bill($this->order('100'), '110');

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->status);
        $this->assertSame(PurchaseBill::MATCH_EXCEPTION, $bill->fresh()->match_state, '⛔ সুইচ বন্ধে দাগ পড়েনি।');
    }

    public function test_the_migration_switches_it_off_in_every_company_that_exists_and_leaves_a_set_row_alone(): void
    {
        $ids = DB::table('companies')->pluck('id');
        DB::table('settings')->where('key', 'purchase.block_order_price_mismatch')->delete();
        $kept = (int) $ids->first();
        DB::table('settings')->insert(['company_id' => $kept, 'module' => 'purchase', 'key' => 'purchase.block_order_price_mismatch',
            'type' => 'boolean', 'value' => '1', 'group' => 'entry', 'created_at' => now(), 'updated_at' => now()]);

        (require base_path('app/Modules/Purchase/Database/Migrations/2027_02_17_100000_the_order_rate_was_never_held_against_the_bill.php'))->up();

        $rows = DB::table('settings')->where('key', 'purchase.block_order_price_mismatch')->pluck('value', 'company_id');
        $this->assertCount($ids->count(), $rows, '⛔ সব কোম্পানিতে সারি বসেনি।');
        $this->assertSame('1', (string) $rows[$kept], '⛔ আগে বসানো সারি বদলে গেল।');
        $this->assertSame(['0'], $rows->except($kept)->map(fn ($v) => (string) $v)->unique()->values()->all(), '⛔ আজকের কোম্পানিতে বন্ধ লেখা হয়নি।');
    }

    private function order(string $rate): PurchaseOrder
    {
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'ordered_qty' => '10', 'rate' => $rate]]);

        return $orders->confirm($order)->load('lines');
    }

    private function bill(PurchaseOrder $order, string $rate): PurchaseBill
    {
        $bills = app(PurchaseBillService::class);
        $draft = $bills->create(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'OR-'.fake()->unique()->numberBetween(10000, 99999)],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => $rate, 'purchase_order_line_id' => $order->lines->first()->id]]);

        return $bills->confirm($draft)->fresh();
    }
}
