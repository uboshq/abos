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
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * পুরনো বাকি বিলটা পরিশোধে বাছা যেত না — পুরো ERP অডিট, ক্রয় ⚠️১৩ (৬ অক্টোবর ২০২৬)।
 *
 * ⛔ পরিশোধের পর্দা সাম্প্রতিক ২০০টা নিশ্চিত বিল এনে তারপর বাকিগুলো রাখত — ২০০-র পরের পুরনো বাকি বিল কোনোদিন আসত না; ফেরতের
 * পর্দাও বিলের পাতা থেকে আসা পুরনো বিলটা দেখাত না। ⭐ এখন বাকি থাকা বিল ডাটাবেজেই ছাঁকা ([[PurchaseBill::scopeStillOwed()]]),
 * পুরনোটা আগে; ফেরতে বাছা বিলটা তালিকায় থাকেই।
 */
final class TheOldDueBillCouldNotBePickedTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_old_unpaid_bill_behind_two_hundred_newer_ones_is_still_offered(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $old = app(DirectPurchaseService::class)->complete([
            'supplier_id' => Supplier::query()->orderBy('id')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'OLD-'.fake()->unique()->numberBetween(10000, 99999),
        ], [['product_id' => Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->value('id'),
            'qty' => '3', 'rate' => '100', 'sales_price' => '100', 'tax' => '0']])['bill'];
        DB::table('pur_bills')->where('id', $old->id)->update(['trx_date' => now()->subYear()->toDateString()]);
        $this->assertSame(0, bccomp($old->fresh()->dueAmount(), (string) $old->total, 4), 'দৃশ্যটাই বানানো যায়নি — পুরনো বিলটা পুরো বাকি থাকার কথা।');

        // ⓘ পরের ২০০টা — নতুন, নিশ্চিত, কিছু বাকি নেই (মোট শূন্য)
        $rows = [];
        for ($i = 1; $i <= 200; $i++) {
            $rows[] = ['public_id' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $old->branch_id,
                'document_no' => 'NEW-'.$i, 'supplier_id' => $old->supplier_id, 'warehouse_id' => $old->warehouse_id,
                'trx_date' => now()->toDateString(), 'status' => DocumentStatus::CONFIRMED, 'total' => 0,
                'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('pur_bills')->insert($rows);

        $offered = collect($this->get(route('purchase.payment.create'))->assertOk()->viewData('openBills'))->pluck('id')->all();
        $this->assertContains($old->id, $offered, '⛔ ২০০টা নতুন বিলের আড়ালে পুরনো বাকি বিলটা পরিশোধে বাছা যায় না।');
        $this->assertSame([$old->id], $offered, '⛔ কিছু বাকি নেই এমন বিলও পরিশোধের তালিকায়।');

        // ⓘ ছাঁকনি আর বিলের নিজের হিসাব একই — পুরো শোধ হলে তালিকা থেকে নামে
        $this->assertTrue(PurchaseBill::query()->stillOwed()->whereKey($old->id)->exists());
        DB::table('pur_bills')->where('id', $old->id)->update(['total' => 0]);
        $this->assertFalse(PurchaseBill::query()->stillOwed()->whereKey($old->id)->exists(), '⛔ বাকি নেই, তবু "বাকি আছে" ছাঁকনিতে।');
        DB::table('pur_bills')->where('id', $old->id)->update(['total' => $old->total]);

        // ⓘ বিলের পাতা থেকে পুরনো বিলের ফেরত — বাছা বিলটা তালিকায়
        $bills = collect($this->get(route('purchase.return.create', ['purchase_bill_id' => $old->id]))->assertOk()->viewData('bills'))->pluck('id')->all();
        $this->assertContains($old->id, $bills, '⛔ ফেরতের পর্দায় পুরনো বিলটা নেই, অথচ সারিগুলো ঐ বিলেরই।');
    }
}
