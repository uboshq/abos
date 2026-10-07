<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ পরিশোধ হয়ে যাওয়া বেতন-রান বাতিল হত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (HR ⚠️৬)।
 *
 * ⓘ বেতন দেওয়া হয় আলাদা ভাউচারে ("প্রদেয় বেতন" ডেবিট), রানের সাথে বাঁধা নেই। বাতিলের উল্টো দাখিলা দেনাটা আবার ডেবিট করত —
 * টাকা কর্মীর হাতে, খাতায় বেতন-দেনা ঋণাত্মক, বা পরের মাসের দেনা নীরবে খেয়ে ফেলা। এখন কিছু পরিশোধ হয়ে থাকলে বাতিল থামে
 * ([[PayrollService::assertSalaryNotPaidOut()]]), আগে আসা আগে শোধ ধরে।
 */
final class APaidPayrollCannotBeCancelledTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private int $cash;

    private int $payable;

    private string $before;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SalaryHeadService::class)->installDefaults();

        $employee = app(EmployeeService::class)->create(['code' => 'EMP-PD', 'name_en' => 'Paid', 'joining_date' => '2026-01-15', 'payment_method' => 'cash']);
        app(SalaryStructureService::class)->set($employee, SalaryHead::query()->where('code', 'BASIC')->firstOrFail(), '2026-01-15', '20000');

        $till = app(CashTillService::class)->ensurePrimaryTill();
        $this->cash = (int) $till->account_id;
        $this->payable = (int) StandardChart::find(StandardChart::SALARY_PAYABLE)->id;
        $this->putMoneyIn($till->account, '1000000', '2026-07-01');
        $this->before = $this->owed();
    }

    public function test_a_partly_paid_run_stays_until_the_payment_is_undone(): void
    {
        $run = $this->confirmed('2026-08-01');
        $paid = $this->pay('5000', '2026-09-02');

        $said = $this->cancelling($run);

        $this->assertNotNull($said, '⛔ পরিশোধ হয়ে যাওয়া বেতন-রান বাতিল হয়ে গেল');
        $this->assertStringContainsString(Money::format('5000'), $said, 'বার্তায় কত পরিশোধ হয়েছে তা নেই');
        $this->assertSame(DocumentStatus::CONFIRMED, $run->fresh()->status);
        $this->assertSame(0, $this->reversals($run), '⛔ থেমে যাওয়া বাতিলেও উল্টো দাখিলা বসল');

        // ⓘ পরিশোধের ভাউচার উল্টালে দেনা পুরো ফিরল — এখন রান বাতিল হয় (সমান হলে থামা নয়)
        app(PostingEngine::class)->reverse(sourceType: 'payment_voucher', sourceId: $paid, reversalDate: '2026-09-03', reason: 'ভুল');

        $this->assertNull($this->cancelling($run), 'পরিশোধ উল্টানোর পরেও রান বাতিল হল না');
        $this->assertSame(DocumentStatus::CANCELLED, $run->fresh()->status);
        $this->assertGreaterThan(0, $this->reversals($run));
        $this->assertSame($this->before, $this->owed(), 'বাতিলের পরে বেতন-দেনা আগের জায়গায় ফেরার কথা');
    }

    public function test_a_later_months_dues_do_not_cover_a_paid_earlier_month(): void
    {
        $august = $this->confirmed('2026-08-01');
        $this->pay((string) $august->net_total, '2026-09-02');
        $september = $this->confirmed('2026-09-01');

        // ⓘ খাতায় সেপ্টেম্বরের দেনা বাকি, আর সেটা আগস্টের সমান — তবু আগস্ট পরিশোধিত, বাতিল নয়
        $this->assertNotNull($this->cancelling($august), '⛔ পরিশোধিত আগস্ট বাতিল হল — সেপ্টেম্বরের দেনা খেয়ে ফেলা হল');
        $this->assertSame(DocumentStatus::CONFIRMED, $august->fresh()->status);

        $this->assertNull($this->cancelling($september), 'বাকি সেপ্টেম্বর বাতিল হওয়ার কথা');
        $this->assertSame($this->before, $this->owed(), 'সেপ্টেম্বর বাতিলের পরে দেনা আগের জায়গায় ফেরার কথা');
    }

    public function test_a_cancelled_later_month_does_not_hold_an_unpaid_earlier_one(): void
    {
        $august = $this->confirmed('2026-08-01');
        $september = $this->confirmed('2026-09-01');
        $this->assertNull($this->cancelling($september), 'প্রস্তুতিটাই ভুল — বাকি সেপ্টেম্বর বাতিল হওয়ার কথা');

        // ⓘ বাতিল সেপ্টেম্বরের দেনা আর বাকি নেই — সেটা ধরলে বাকি আগস্টকে ভুল করে "পরিশোধিত" দেখাত
        $this->assertNull($this->cancelling($august), '⛔ বাতিল হয়ে যাওয়া পরের মাস বাকি আগস্টের বাতিল আটকাল');
        $this->assertSame($this->before, $this->owed());
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function confirmed(string $month): PayrollRun
    {
        return app(PayrollService::class)->confirm(app(PayrollService::class)->build($month));
    }

    private function pay(string $amount, string $on): int
    {
        $id = random_int(1, 9_999_999);

        app(PostingEngine::class)->post(sourceType: 'payment_voucher', sourceId: $id, trxDate: $on, lines: [
            ['account_id' => $this->payable, 'debit' => $amount],
            ['account_id' => $this->cash, 'credit' => $amount],
        ], branchId: CompanyContext::branchId());

        return $id;
    }

    private function cancelling(PayrollRun $run): ?string
    {
        try {
            app(PayrollService::class)->cancel(PayrollRun::query()->findOrFail($run->id), 'ভুল');
        } catch (ValidationException $e) {
            return $e->errors()['status'][0] ?? 'অন্য ঘরে আটকাল';
        }

        return null;
    }

    private function reversals(PayrollRun $run): int
    {
        return LedgerEntry::query()->where('source_type', PayrollRun::SOURCE_TYPE.':reversal')->where('source_id', $run->id)->count();
    }

    private function owed(): string
    {
        return bcadd((string) LedgerEntry::query()->where('account_id', $this->payable)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as n')->value('n'), '0', 2);
    }
}
