<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Reports\CollectionDueReport;
use App\Modules\Sales\Reports\CreditControlReports;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ বকেয়ার রিপোর্টের "সীমার ব্যবহার" বিল থেকে গোনা হত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (বিক্রয় ⚠️১১)।
 *
 * ⓘ [[CollectionDueReport]] বিলের বাকি ÷ সীমা দেখাত: খোলা জের আর অপেক্ষার কাগজ বাদ। অথচ সীমার দেয়াল খাতা + অপেক্ষার কাগজ গোনে —
 * রিপোর্ট বলত ১০%, দেয়াল আটকাত। এখন "বাকি-নিয়ন্ত্রণ" রিপোর্টের একই অঙ্ক।
 */
final class TheDueReportMeasuresTheLimitLikeTheWallTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_limit_use_counts_the_books_and_the_waiting_papers(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $customer = Customer::query()->create(['code' => 'WALL-1', 'name_en' => 'Wall Traders', 'name_bn' => 'Wall Traders', 'is_active' => true]);
        $customer->forceFill(['credit_limit' => '10000'])->save();

        $invoice = fn (string $no, string $status, string $total) => DB::table('sal_invoices')->insert([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'document_no' => $no,
            'customer_id' => $customer->id, 'trx_date' => now()->subDays(5)->toDateString(), 'due_on' => now()->subDay()->toDateString(),
            'total' => $total, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // ⓘ পাকা বিল ১,০০০ (খাতায়ও), খাতায় আগের খোলা জের ৩,০০০ (কোনো বিল নেই), আর অপেক্ষার খসড়া বিল ৫০০
        $invoice('WALL-B1', 'confirmed', '1000');
        $invoice('WALL-D1', 'draft', '500');
        foreach ([['sales_invoice', 990101, 1000], ['opening_balance', 990102, 3000]] as [$source, $id, $amount]) {
            DB::table('ledger_entries')->insert([
                'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id,
                'financial_year_id' => FinancialYear::query()->where('is_current', true)->value('id'),
                'account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id,
                'trx_date' => now()->subDays(5)->toDateString(), 'source_type' => $source, 'source_id' => $id,
                'party_type' => 'customer', 'party_id' => $customer->id, 'debit' => $amount, 'credit' => 0,
            ]);
        }

        // ⓘ আর হাতে আসা, এখনো ক্লিয়ার না হওয়া ১,০০০ টাকার চেক — দেয়াল সেটাও গোনে
        DB::table('acc_cheques')->insert([
            'company_id' => $company->id, 'direction' => 'received', 'party_type' => 'customer', 'party_id' => $customer->id,
            'cheque_date' => now()->toDateString(), 'received_on' => now()->toDateString(), 'cheque_no' => 'WALL-CHQ-1',
            'amount' => 1000, 'status' => 'pending', 'voucher_id' => 990103, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $due = collect(app(ReportEngine::class)->run(CollectionDueReport::KEY, ['customer_id' => (string) $customer->id], perPage: 500)->rows)
            ->firstWhere('customer_code', 'WALL-1');
        $wall = collect(app(ReportEngine::class)->run(CreditControlReports::CREDIT_USE, [], perPage: 5000)->rows)
            ->firstWhere('customer_code', 'WALL-1');

        $this->assertSame(0, bccomp((string) $due['total_due'], '1000', 2), 'বিলের বকেয়ার কলাম আগের মতোই বিল থেকে');
        $this->assertSame(0, bccomp((string) $due['limit_used'], '55', 1), '⛔ সীমার ব্যবহার খাতা (৪,০০০), অপেক্ষার কাগজ (৫০০) আর চেক (১,০০০) থেকে নয় — '.$due['limit_used']);
        $this->assertSame(0, bccomp((string) $due['limit_used'], (string) $wall['used_percent'], 1), '⛔ বকেয়ার রিপোর্ট আর বাকি-নিয়ন্ত্রণ রিপোর্ট আলাদা কথা বলে');
    }
}
