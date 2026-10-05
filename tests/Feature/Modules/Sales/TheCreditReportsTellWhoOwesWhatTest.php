<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Sales\Reports\CreditControlReports;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⭐ বাকি ও আদায়ের রিপোর্ট — সীমার ব্যবহার, বাকি বন্ধ, ঝুঁকির গ্রাহক, সীমা বদলের ইতিহাস (৫ অক্টোবর ২০২৬;
 * [[CreditControlReports]])।
 *
 *   · USE: সীমা ১০,০০০, বিল ৪,০০০, হাতে আসার দিনেই জমায় বসা ১,০০০-এর চেক এখনো ক্লিয়ার নয় → খাতায় বকেয়া ৩,০০০,
 *     আটকে আছে ১,০০০, অবশিষ্ট ৬,০০০, ব্যবহার ৪০%। ⓘ দেয়ালের একই অঙ্ক — চেক বাদ দিলে অবশিষ্ট ৭,০০০ দেখাত।
 *   · BLOCKED: হাতে বন্ধ করা গ্রাহক, কারণসহ; অন্যরা নেই।
 *   · RISK: ৯০ দিনের পুরনো বিল ৩,০০০ (৬০ দিনের দেয়াল), ঝুঁকির আচরণ-নোট, সীমা পার — তিনজনই; পরিষ্কার জন নেই।
 *   · HISTORY: সীমা বাড়ানোর সইয়ের অনুরোধ।
 */
final class TheCreditReportsTellWhoOwesWhatTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        app(SettingsService::class)->set('customer.overdue_block_days', 60);
    }

    public function test_the_limit_use_counts_what_the_wall_counts(): void
    {
        $c = $this->customer('USE-1', '10000');
        $this->owes($c, '4000');
        $this->unclearedCheque($c, '1000');

        $row = $this->row(CreditControlReports::CREDIT_USE, 'USE-1');

        $this->assertSame(0, bccomp((string) $row['outstanding'], '3000', 2), 'খাতায় ৪,০০০ − চেকের জমা ১,০০০');
        $this->assertSame(0, bccomp((string) $row['held'], '1000', 2), '⛔ ক্লিয়ার না হওয়া চেক আটকে থাকা টাকায় নেই।');
        $this->assertSame(0, bccomp((string) $row['available'], '6000', 2), '⛔ অবশিষ্ট সীমা দেয়ালের অঙ্ক নয়: '.$row['available']);
        $this->assertSame(0, bccomp((string) $row['used_percent'], '40', 1), '⛔ ব্যবহার ৪০% হওয়ার কথা: '.$row['used_percent']);

        $this->get(route('sales.report.show', ['slug' => 'credit-use']))->assertOk()->assertSee('USE-1');
    }

    public function test_the_blocked_list_holds_only_the_blocked(): void
    {
        $blocked = $this->customer('BLK-1', '5000');
        $this->customer('BLK-2', '5000');
        app(CustomerService::class)->blockCredit($blocked, 'চেক ফেরত');

        $rows = collect(app(ReportEngine::class)->run(CreditControlReports::BLOCKED_CUSTOMERS, [], perPage: 500)->rows);

        $this->assertSame('চেক ফেরত', (string) $rows->firstWhere('customer_code', 'BLK-1')['reason']);
        $this->assertNull($rows->firstWhere('customer_code', 'BLK-2'), '⛔ বাকি বন্ধ নন এমন গ্রাহক তালিকায়।');

        $this->get(route('sales.report.show', ['slug' => 'blocked-customers']))->assertOk()->assertSee('BLK-1');
    }

    public function test_the_risk_list_names_each_sign_and_leaves_the_clean_out(): void
    {
        $late = $this->customer('RSK-LATE', '100000');
        $this->bill($late, 'INV-RSK1', '3000', daysPastDue: 90);

        $flagged = $this->customer('RSK-FLAG', '100000');
        DB::table('customer_conduct_notes')->insert([
            'public_id' => (string) Str::uuid7(), 'company_id' => $this->company->id, 'customer_id' => $flagged->id,
            'type' => 'CHEQUE_DISHONOURED', 'is_active' => true, 'recorded_by' => auth()->id(), 'recorded_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $over = $this->customer('RSK-OVER', '1000');
        $this->owes($over, '1500');

        $clean = $this->customer('RSK-CLEAN', '100000');
        $this->owes($clean, '500');

        $rows = collect(app(ReportEngine::class)->run(CreditControlReports::RISKY_CUSTOMERS, [], perPage: 500)->rows);

        $this->assertSame(0, bccomp((string) $rows->firstWhere('customer_code', 'RSK-LATE')['overdue_amount'], '3000', 2));
        $this->assertSame(0, bccomp((string) $rows->firstWhere('customer_code', 'RSK-FLAG')['risk_flags'], '1', 0));
        $this->assertSame(0, bccomp((string) $rows->firstWhere('customer_code', 'RSK-OVER')['over_limit'], '500', 2));
        $this->assertNull($rows->firstWhere('customer_code', 'RSK-CLEAN'), '⛔ পরিষ্কার গ্রাহক ঝুঁকির তালিকায়।');

        $this->get(route('sales.report.show', ['slug' => 'risky-customers']))->assertOk()->assertSee('RSK-LATE');
    }

    public function test_the_history_shows_each_limit_request(): void
    {
        $c = $this->customer('HIS-1', '1000');
        DB::table('approvals')->insert([
            'company_id' => $this->company->id, 'approvable_type' => $c->getMorphClass(), 'approvable_id' => $c->id,
            'module' => 'customer', 'action' => 'credit_limit', 'amount' => '50000', 'status' => 'pending',
            'current_level' => 1, 'requested_reason' => '1,000 → 50,000', 'requested_by' => auth()->id(),
            'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = $this->row(CreditControlReports::LIMIT_HISTORY, 'HIS-1');
        $this->assertSame(0, bccomp((string) $row['asked_limit'], '50000', 2));
        $this->assertSame(__('sales::credit.status_pending'), $row['status']);

        $this->get(route('sales.report.show', ['slug' => 'limit-history']))->assertOk()->assertSee('HIS-1');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function row(string $key, string $code): array
    {
        $row = collect(app(ReportEngine::class)->run($key, [], perPage: 500)->rows)->firstWhere('customer_code', $code);
        $this->assertNotNull($row, "প্রস্তুতিটাই ভুল — {$code}-এর সারি নেই।");

        return (array) $row;
    }

    private function customer(string $code, string $limit): Customer
    {
        return Customer::query()->create([
            'code' => $code, 'name_en' => $code, 'name_bn' => $code, 'is_active' => true, 'credit_limit' => $limit,
            'branch_id' => $this->company->defaultBranch()?->id,
        ]);
    }

    private function owes(Customer $c, string $amount): void
    {
        $this->postLines([
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => $amount, 'party_type' => 'customer', 'party_id' => $c->id],
            ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'credit' => $amount],
        ]);
    }

    private function unclearedCheque(Customer $c, string $amount): void
    {
        $id = (int) DB::table('acc_cheques')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'direction' => 'received', 'cheque_date' => now()->toDateString(), 'received_on' => now()->toDateString(),
            'cheque_no' => 'RPT-'.random_int(1000, 9999), 'amount' => $amount, 'party_type' => 'customer', 'party_id' => $c->id,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postLines([
            ['account_id' => StandardChart::find(StandardChart::CHEQUES_IN_HAND)->id, 'debit' => $amount],
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => $amount, 'party_type' => 'customer', 'party_id' => $c->id],
        ], 'cheque', $id);
    }

    private function bill(Customer $c, string $no, string $amount, int $daysPastDue): void
    {
        $id = (int) DB::table('sal_invoices')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => $no, 'customer_id' => $c->id, 'trx_date' => now()->subDays($daysPastDue + 10)->toDateString(),
            'due_on' => now()->subDays($daysPastDue)->toDateString(), 'subtotal' => $amount, 'total' => $amount,
            'status' => DocumentStatus::CONFIRMED, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postLines([
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => $amount, 'party_type' => 'customer', 'party_id' => $c->id],
            ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'credit' => $amount],
        ], 'sales_invoice', $id);
    }

    private function postLines(array $lines, string $source = 'test:credit-report', ?int $sourceId = null): void
    {
        app(PostingEngine::class)->post(
            sourceType: $source, sourceId: $sourceId ?? random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: $lines,
            branchId: $this->company->defaultBranch()?->id,
        );
    }
}
