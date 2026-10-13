<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\SettingsService;
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
 * ⛔ বাকির রিপোর্টগুলো দেয়ালের চেয়ে অন্য মাপে বিচার করত — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা।
 *
 * ⓘ সাধারণ সীমা ১,০০০, চলতি অস্থায়ী সীমা ৫,০০০, খাতায় বকেয়া ৩,০০০ → দেয়ালে জায়গা আছে (৬০%)। রিপোর্টগুলো কেবল `credit_limit` পড়ত:
 * সীমার ব্যবহার ৩০০%, "ঝুঁকির গ্রাহক"-এ সীমা পার ২,০০০। আর সীমার সুইচ বন্ধ কোম্পানিতেও "সীমা পার" ঝুঁকি বলত।
 */
final class TheCreditReportsJudgeLikeTheWallTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->customer = Customer::query()->create(['code' => 'TEMP-1', 'name_en' => 'Temp Traders', 'name_bn' => 'Temp Traders', 'is_active' => true]);
        $this->customer->forceFill([
            'credit_limit' => '1000', 'temp_credit_limit' => '5000', 'temp_credit_limit_until' => now()->addDays(3)->toDateString(),
        ])->save();

        // ⓘ আজকের পাকা বিল ৩,০০০, মেয়াদ আজ — খাতায়ও ৩,০০০
        DB::table('sal_invoices')->insert([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'document_no' => 'TEMP-B1',
            'customer_id' => $this->customer->id, 'trx_date' => now()->toDateString(), 'due_on' => now()->toDateString(),
            'total' => '3000', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ledger_entries')->insert([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->value('id'),
            'account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id,
            'trx_date' => now()->toDateString(), 'source_type' => 'sales_invoice', 'source_id' => 990201,
            'party_type' => 'customer', 'party_id' => $this->customer->id, 'debit' => 3000, 'credit' => 0,
        ]);
    }

    public function test_the_live_temporary_limit_is_the_limit_in_every_credit_report(): void
    {
        $use = $this->row(CreditControlReports::CREDIT_USE, []);
        $this->assertSame(0, bccomp((string) $use['credit_limit'], '5000', 2), '⛔ সীমার ব্যবহারে অস্থায়ী সীমা নেই — '.$use['credit_limit']);
        $this->assertSame(0, bccomp((string) $use['available'], '2000', 2));
        $this->assertSame(0, bccomp((string) $use['used_percent'], '60', 1));

        $due = $this->row(CollectionDueReport::KEY, ['customer_id' => (string) $this->customer->id]);
        $this->assertSame(0, bccomp((string) $due['credit_limit'], '5000', 2), '⛔ আদায়ের সূচিতে অস্থায়ী সীমা নেই');
        $this->assertSame(0, bccomp((string) $due['limit_used'], '60', 1));

        $this->assertNull($this->row(CreditControlReports::RISKY_CUSTOMERS, [], false), '⛔ অস্থায়ী সীমার ভিতরের গ্রাহক "সীমা পার" ঝুঁকিতে');
    }

    public function test_with_the_credit_switch_off_nobody_is_over_the_limit(): void
    {
        $this->customer->forceFill(['temp_credit_limit' => null, 'temp_credit_limit_until' => null])->save();

        $this->assertNotNull($this->row(CreditControlReports::RISKY_CUSTOMERS, []), 'প্রস্তুতিটাই ভুল — সুইচ চালু থাকতে সীমা পার নয়।');

        app(SettingsService::class)->set('customer.credit_limit_enabled', false);

        $this->assertNull($this->row(CreditControlReports::RISKY_CUSTOMERS, [], false), '⛔ সীমার সুইচ বন্ধ, তবু "সীমা পার" ঝুঁকি');
    }

    /** @return array<string, mixed>|null */
    private function row(string $key, array $filters, bool $mustExist = true): ?array
    {
        $row = collect(app(ReportEngine::class)->run($key, $filters, perPage: 5000)->rows)
            ->map(fn ($r) => (array) $r)
            ->firstWhere('customer_code', 'TEMP-1');

        $mustExist && $this->assertNotNull($row, "TEMP-1 {$key}-এ নেই।");

        return $row;
    }
}
