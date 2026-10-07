<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Reports\CollectionDueReport;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * আদায়ের সূচি — কার কাছে আজ যেতে হবে, সপ্তাহে কত আসার কথা, সীমার কত ব্যবহার। রিপোর্ট সেন্টার ধাপ ৪, ২ অক্টোবর ২০২৬
 * ([[CollectionDueReport]])।
 *
 * একজন গ্রাহক, সীমা ১০,০০০; চারটা বিল — গতকাল মেয়াদ পেরোনো ১০০, আজ ২০০, তিন দিন পরে ৩০০, ত্রিশ দিন পরে ৪০০।
 * ⓘ শেষেরটা "এই মাসের বাকিটা" ঘরে পড়ে না (৩০ দিন মাস পেরোয়), কেবল মোটে।
 */
final class TheCollectionsDueSayWhoToVisitTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_bill_lands_in_its_window_and_the_limit_use_is_shown(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $customer = Customer::query()->create(['code' => 'DUE-1', 'name_en' => 'Due Traders', 'name_bn' => 'Due Traders', 'is_active' => true]);
        $customer->forceFill(['credit_limit' => '10000'])->save();

        foreach ([[-1, '100'], [0, '200'], [3, '300'], [30, '400']] as [$days, $total]) {
            DB::table('sal_invoices')->insert([
                'company_id' => $company->id,
                'branch_id' => $company->defaultBranch()?->id,
                'document_no' => 'DUE-'.$days,
                'customer_id' => $customer->id,
                'trx_date' => now()->subDays(10)->toDateString(),
                'due_on' => now()->addDays($days)->toDateString(),
                'total' => $total,
                'status' => 'confirmed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ⓘ বিলগুলো খাতায় — সীমার ব্যবহার এখন খাতা থেকে (বিক্রয় ⚠️১১, [[TheDueReportMeasuresTheLimitLikeTheWallTest]])
        DB::table('ledger_entries')->insert([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id,
            'financial_year_id' => \App\Models\FinancialYear::query()->where('is_current', true)->value('id'),
            'account_id' => \App\Modules\Accounts\Services\StandardChart::find(\App\Modules\Accounts\Services\StandardChart::RECEIVABLE)->id,
            'trx_date' => now()->subDays(10)->toDateString(), 'source_type' => 'sales_invoice', 'source_id' => 990001,
            'party_type' => 'customer', 'party_id' => $customer->id, 'debit' => 1000, 'credit' => 0,
        ]);

        $row = collect(app(ReportEngine::class)->run(CollectionDueReport::KEY, ['customer_id' => (string) $customer->id], perPage: 500)->rows)
            ->firstWhere('customer_code', 'DUE-1');

        $this->assertNotNull($row, 'প্রস্তুতিটাই ভুল — গ্রাহকের সারি নেই।');
        $this->assertSame(0, bccomp((string) $row['overdue'], '100', 2), '⛔ মেয়াদ পেরোনো বাকি ভুল।');
        $this->assertSame(0, bccomp((string) $row['due_today'], '200', 2), '⛔ আজকের বাকি ভুল।');
        $this->assertSame(0, bccomp((string) $row['due_week'], '300', 2), '⛔ পরের ৭ দিনের বাকি ভুল।');
        $this->assertSame(0, bccomp((string) $row['total_due'], '1000', 2), '⛔ মোট বাকি ভুল।');
        $this->assertSame(0, bccomp((string) $row['limit_used'], '10', 1), '⛔ সীমার ব্যবহার ১০% হওয়ার কথা।');

        $this->get(route('sales.report.show', ['slug' => 'collection-due']))->assertOk()->assertSee('Due Traders');
    }
}
