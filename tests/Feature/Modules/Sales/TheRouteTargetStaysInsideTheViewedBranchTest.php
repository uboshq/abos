<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Services\RouteMetrics;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ রুটের অর্জন সব শাখার বিল গুনত — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা।
 *
 * ⓘ [[RouteMetrics::targetsFor()]] কেবল কোম্পানি ছাঁকত, অথচ একই পর্দার খতিয়ানের অঙ্ক হেডারে বাছা শাখা আর ডিলারের দেয়াল মানে।
 * এক শাখা দেখা মালিক রুটের অর্জনে অন্য শাখার বিলও পেতেন।
 */
final class TheRouteTargetStaysInsideTheViewedBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_route_achievement_counts_only_the_viewed_branch(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $here = (int) Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->value('id');
        $there = (int) Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('id', '<>', $here)->orderBy('id')->value('id');
        $this->assertGreaterThan(0, $there, 'প্রস্তুতিটাই ভুল — দ্বিতীয় শাখা নেই।');

        CompanyContext::set($company->id, $here);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['view_all_branches' => false])->save();
        $this->actingAs($owner->fresh());

        $customer = (int) Customer::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $product = (int) Product::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $route = (int) DB::table('mdm_locations')->orderBy('id')->value('id');
        DB::table('customers')->where('id', $customer)->update(['location_id' => $route]);

        foreach ([[$here, 'ZR-1', 1000], [$there, 'ZR-2', 700]] as [$branch, $no, $amount]) {
            $invoice = DB::table('sal_invoices')->insertGetId([
                'company_id' => $company->id, 'branch_id' => $branch, 'document_no' => $no, 'customer_id' => $customer,
                'trx_date' => '2019-03-10', 'status' => DocumentStatus::CONFIRMED, 'subtotal' => $amount, 'discount' => 0,
                'bill_discount' => 0, 'tax' => 0, 'freight_charge' => 0, 'total' => $amount, 'cost_of_goods' => 0,
            ]);
            DB::table('sal_invoice_lines')->insert([
                'sales_invoice_id' => $invoice, 'product_id' => $product, 'qty' => 1, 'rate' => $amount, 'discount' => 0,
                'tax' => 0, 'amount' => $amount, 'unit_cost' => 0, 'line_no' => 1,
            ]);
        }

        $row = app(RouteMetrics::class)->targetsFor([$route], '2019-03-01')[$route];

        $this->assertEqualsWithDelta(1000.0, (float) $row['achieved'], 0.001, '⛔ রুটের অর্জনে বাছা শাখার বাইরের বিল ঢুকল');
    }
}
