<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Services\Rankings;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ অন্য মাপে সাজানো তালিকা সারি হারাত — ec, Executive লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ পণ্য ধরে বিক্রি লাভ ধরে বড় থেকে ছোট সাজায়, আর মালিকের তালিকা প্রথম ২০০ সারি টেনে তারপর মার্জিন ধরে সাজাত। ২০৫টা পণ্যের
 * সবচেয়ে কম লাভেরটা (−৮% মার্জিন) ২০৫ নম্বরে — কাটা পড়ত, "কম মার্জিন" তালিকায় আসতই না।
 */
final class TheRankingsSortEveryRowTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_lowest_margin_product_is_first_even_past_the_first_two_hundred_rows(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $branch = (int) Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->value('id');
        CompanyContext::set($company->id, $branch);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $customer = (int) Customer::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id');
        $unit = Unit::query()->orderBy('id')->firstOrFail()->id;

        $invoice = DB::table('sal_invoices')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $branch, 'document_no' => 'ZR-1', 'customer_id' => $customer,
            'trx_date' => '2019-02-10', 'status' => DocumentStatus::CONFIRMED, 'subtotal' => 204100, 'discount' => 0,
            'bill_discount' => 0, 'rounding_amount' => 0, 'tax' => 0, 'freight_charge' => 0, 'total' => 204100,
            'cost_of_goods' => 102108, 'created_by' => $owner->id,
        ]);

        // ⓘ ২০৪টা পণ্য ৫০% মার্জিনে (লাভ ৫০০ করে), একটা −৮% (লাভ −৮) — রিপোর্টের নিজের ক্রমে সেটা সবার শেষে
        $lines = [];
        $thinId = 0;
        for ($n = 1; $n <= 205; $n++) {
            $thin = $n === 205;
            $product = Product::query()->create([
                'code' => sprintf('ZR-%03d', $n), 'name_en' => ($thin ? 'ZR-THIN' : 'ZR-'.$n).' probe', 'name_bn' => 'ZR নমুনা '.$n,
                'unit_id' => $unit, 'is_active' => true,
            ]);
            if ($thin) {
                $thinId = (int) $product->id;
            }
            $lines[] = ['sales_invoice_id' => $invoice, 'product_id' => $product->id, 'qty' => 1, 'rate' => $thin ? 100 : 1000,
                'discount' => 0, 'tax' => 0, 'amount' => $thin ? 100 : 1000, 'unit_cost' => $thin ? 108 : 500, 'line_no' => $n];
        }
        DB::table('sal_invoice_lines')->insert($lines);

        $thin = app(Rankings::class)->top($owner, [['id' => (int) $company->id, 'name' => 'TDEPOT', 'only' => null]],
            'sales.by_product', 'margin_percent', 10, ['from' => '2019-02-01', 'to' => '2019-02-28'], ascending: true)['rows'];

        $this->assertNotEmpty($thin, 'প্রস্তুতিটাই ভুল — তালিকা খালি।');
        $this->assertSame($thinId, (int) $thin[0]['product_id'], '⛔ সবচেয়ে কম মার্জিনের পণ্য তালিকার মাথায় নেই — ২০০ সারির পরে কাটা পড়েছে।');
        $this->assertSame(0, bccomp((string) $thin[0]['margin_percent'], '-8', 2));
    }
}
