<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Purchase\Reports\PaymentDueReport;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * পরিশোধের সূচি — আজ কাকে দিতে হবে, সপ্তাহে কত যাবে। রিপোর্ট সেন্টার ধাপ ৪, ২ অক্টোবর ২০২৬ ([[PaymentDueReport]])।
 *
 * একজন সরবরাহকারী; চারটা বিল — গতকাল মেয়াদ পেরোনো ১০০, আজ ২০০, তিন দিন পরে ৩০০, ত্রিশ দিন পরে ৪০০;
 * আর একটা খসড়া ৯০০০, যেটা দেনা নয়।
 */
final class ThePaymentsDueSayWhomToPayTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_bill_lands_in_its_window_and_a_draft_is_no_debt(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $supplier = Supplier::query()->create(['code' => 'PAY-1', 'name_en' => 'Pay Traders', 'name_bn' => 'Pay Traders']);

        foreach ([[-1, '100', 'confirmed'], [0, '200', 'confirmed'], [3, '300', 'confirmed'], [30, '400', 'confirmed'], [0, '9000', 'draft']] as $i => [$days, $total, $status]) {
            DB::table('pur_bills')->insert([
                'company_id' => $company->id,
                'branch_id' => $company->defaultBranch()?->id,
                'document_no' => 'PAY-'.$i,
                'supplier_id' => $supplier->id,
                'trx_date' => now()->subDays(10)->toDateString(),
                'due_on' => now()->addDays($days)->toDateString(),
                'total' => $total,
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $row = collect(app(ReportEngine::class)->run(PaymentDueReport::KEY, ['supplier_id' => (string) $supplier->id], perPage: 500)->rows)
            ->firstWhere('supplier_code', 'PAY-1');

        $this->assertNotNull($row, 'প্রস্তুতিটাই ভুল — সরবরাহকারীর সারি নেই।');
        $this->assertSame(0, bccomp((string) $row['overdue'], '100', 2), '⛔ মেয়াদ পেরোনো দেনা ভুল।');
        $this->assertSame(0, bccomp((string) $row['due_today'], '200', 2), '⛔ আজকের দেনা ভুল — খসড়া ঢুকে পড়েছে?');
        $this->assertSame(0, bccomp((string) $row['due_week'], '300', 2), '⛔ পরের ৭ দিনের দেনা ভুল।');
        $this->assertSame(0, bccomp((string) $row['total_due'], '1000', 2), '⛔ মোট দেনা ভুল।');

        $this->get(route('purchase.report.show', ['slug' => 'payment-due']))->assertOk()->assertSee('Pay Traders');
    }
}
