<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Reports\DepositReports;
use App\Modules\Finance\Services\DepositKindInstaller;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ ক — জমা সুদ (অর্থ-মডিউলের পরিকল্পনা, অংশ ৪, ৬ অক্টোবর ২০২৬, [[DepositReports::ACCRUED]])।
 *
 * ⛔ অর্জিত = আসল × হার × দিন ÷ ৩৬৫, প্রতিটা আসলের চলাচল নিজের দিন থেকে, শেষ তোলার পর থেকে, মেয়াদপূর্তি পর্যন্ত। বিপজ্জনক
 * ইনপুট: সইয়ের অপেক্ষার আর বাতিল কিস্তি আর তোলা, মেয়াদ পেরোনো জমা, মালিকের নামের জমা, হার নেই এমন জমা, দিনের আগে বা পরে
 * বন্ধ, সইয়ের অপেক্ষায় খোলা, বাতিল, অন্য শাখা, অন্য কোম্পানি।
 */
final class TheInterestEarnedBeforeMaturityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $mymensingh;

    private Branch $netrakona;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->mymensingh = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->netrakona = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();

        $this->today = now()->startOfDay();

        // ⓘ FDR — ১,০০,০০০, ১০%, ৭৩ দিন আগে (= বছরের এক-পঞ্চমাংশ), কর ১০%; খোলার চলাচলে ভাউচার নেই (পুরনো সারি)
        $fdr = $this->deposit('FDR-A', 'FDR', '100000', '10', -73, matures: 292);
        $this->move($fdr, DepositMovement::OPENED, '100000', -73, null);

        // ⓘ DPS — ৭.৩%: ১০,০০০ একশ দিন আগে, ১০,০০০ পঞ্চাশ দিন আগে; ⛔ সইয়ের অপেক্ষার আর বাতিল কিস্তি গোনা নয়
        $dps = $this->deposit('DPS-B', 'DPS', '40000', '7.3', -100, matures: 900);
        $this->move($dps, DepositMovement::OPENED, '10000', -100, DocumentStatus::CONFIRMED);
        $this->move($dps, DepositMovement::INSTALMENT, '10000', -50, DocumentStatus::CONFIRMED);
        $this->move($dps, DepositMovement::INSTALMENT, '10000', -20, DocumentStatus::DRAFT);
        $this->move($dps, DepositMovement::INSTALMENT, '10000', -10, DocumentStatus::CANCELLED);

        // ⓘ মাসিক মুনাফা — ৭৩,০০০, ১০%, দুইশ দিন আগে; শেষ গোনার মতো তোলা ৩০ দিন আগে; ⛔ ৫ দিন আগের তোলা সইয়ের অপেক্ষায়
        $mis = $this->deposit('MIS-C', 'MIS', '73000', '10', -200, matures: 900);
        $this->move($mis, DepositMovement::OPENED, '73000', -200, DocumentStatus::CONFIRMED);
        $this->move($mis, DepositMovement::PAYOUT, '600', -30, DocumentStatus::CONFIRMED);
        $this->move($mis, DepositMovement::PAYOUT, '600', -5, DocumentStatus::DRAFT);

        // ⓘ মেয়াদ পেরিয়েছে, ভাঙানো হয়নি — ৩৬,৫০০, ১০%, ৪০০ দিন আগে, ৩৫ দিন আগে মেয়াদ: ঠিক ৩৬৫ দিন জমে
        $matured = $this->deposit('FDR-D', 'FDR', '36500', '10', -400, matures: -35);
        $this->move($matured, DepositMovement::OPENED, '36500', -400, DocumentStatus::CONFIRMED);

        // ⓘ মালিকের নামে — ১০,০০০, ৩৬.৫%, দশ দিন আগে
        $owner = $this->deposit('FDR-E', 'FDR', '10000', '36.5', -10, matures: 300, heldBy: Deposit::OWNER);
        $this->move($owner, DepositMovement::OPENED, '10000', -10, DocumentStatus::CONFIRMED);

        // ⓘ হার নেই — সারি আছে, জমা শূন্য
        $noRate = $this->deposit('FDR-F', 'FDR', '5000', null, -30, matures: 300);
        $this->move($noRate, DepositMovement::OPENED, '5000', -30, DocumentStatus::CONFIRMED);

        // ⓘ নেত্রকোনায় — ৩৬,৫০০, ১০%, একশ দিন আগে
        $ntk = $this->deposit('FDR-G', 'FDR', '36500', '10', -100, matures: 300, branch: $this->netrakona);
        $this->move($ntk, DepositMovement::OPENED, '36500', -100, DocumentStatus::CONFIRMED);

        // ⓘ পাঁচ দিন আগে বন্ধ — আজকের হিসাবে নেই, দশ দিন আগের হিসাবে আছে
        $closed = $this->deposit('FDR-H', 'FDR', '36500', '10', -100, matures: 300, status: Deposit::CLOSED, closedOn: -5);
        $this->move($closed, DepositMovement::OPENED, '36500', -100, DocumentStatus::CONFIRMED);

        // ⛔ কখনো নয়
        $this->move($this->deposit('FDR-W', 'FDR', '99999', '10', -50, matures: 300, status: Deposit::AWAITING), DepositMovement::OPENED, '99999', -50, DocumentStatus::DRAFT);
        $this->move($this->deposit('FDR-X', 'FDR', '88888', '10', -50, matures: 300, status: Deposit::CANCELLED), DepositMovement::OPENED, '88888', -50, DocumentStatus::CANCELLED);
        CompanyContext::forCompany((int) Company::query()->where('code', 'FMART')->value('id'), function () {
            app(DepositKindInstaller::class)->install();
            $this->move($this->deposit('FDR-Y', 'FDR', '77777', '10', -50, matures: 300), DepositMovement::OPENED, '77777', -50, null);
        });
    }

    public function test_each_deposit_earns_from_its_own_days_until_maturity(): void
    {
        $rows = $this->accrued();

        $this->assertRow($rows, 'FDR-A', principal: '100000', accrued: '2000.00', tax: '200.00', net: '1800.00');
        $this->assertRow($rows, 'DPS-B', principal: '20000', accrued: '300.00', tax: '30.00', net: '270.00');
        $this->assertRow($rows, 'MIS-C', principal: '73000', accrued: '600.00', tax: '60.00', net: '540.00');
        $this->assertRow($rows, 'FDR-D', principal: '36500', accrued: '3650.00', tax: '365.00', net: '3285.00');
        $this->assertRow($rows, 'FDR-F', principal: '5000', accrued: '0', tax: '0', net: '0');
        $this->assertRow($rows, 'FDR-G', principal: '36500', accrued: '1000.00', tax: '100.00', net: '900.00');

        $owner = $this->row($rows, 'FDR-E');
        $this->assertMoney('100.00', $owner['accrued'], 'মালিকের জমা');
        $this->assertSame(__('finance::deposit_report.holder_owner'), $owner['holder']);
        $this->assertSame(__('finance::deposit_report.holder_business'), $this->row($rows, 'FDR-A')['holder']);

        foreach (['FDR-H', 'FDR-W', 'FDR-X', 'FDR-Y'] as $gone) {
            $this->assertNull($this->find($rows, $gone), "⛔ {$gone} দেখাল");
        }
    }

    /** ⓘ আগের দিনের হিসাব — পরে বন্ধ জমা তখন খোলা, পরে খোলা জমা তখন নেই */
    public function test_an_earlier_day_sees_what_was_open_then(): void
    {
        $rows = $this->accrued(-10);

        $this->assertRow($rows, 'FDR-H', principal: '36500', accrued: '900.00', tax: '90.00', net: '810.00');
        $this->assertRow($rows, 'FDR-A', principal: '100000', accrued: '1726.03', tax: '172.60', net: '1553.43');
        // ⓘ ঠিক সেই দিনে খোলা — সারি আছে, এক দিনও জমেনি; তার আগের দিনে সারিই নেই
        $this->assertRow($rows, 'FDR-E', principal: '10000', accrued: '0', tax: '0', net: '0');
        $this->assertNull($this->find($this->accrued(-11), 'FDR-E'), '⛔ খোলার আগের দিনে জমা দেখাল');

        // ⓘ পরের কিস্তি তখনো আসেনি — ষাট দিন আগে DPS-এর আসল কেবল প্রথম কিস্তি
        $this->assertRow($this->accrued(-60), 'DPS-B', principal: '10000', accrued: '80.00', tax: '8.00', net: '72.00');
    }

    /**
     * ⭐ খ — DPS কিস্তির সময়সূচি: খোলার মাস থেকে প্রতি মাস; খোলার টাকা প্রথম মাসের কিস্তি; সইয়ের অপেক্ষার বা বাতিল কিস্তি
     * দেওয়া নয়; কিস্তির দিন পেরোলে বকেয়া; মেয়াদের পরের মাস নেই; কিস্তির নয় এমন জমা (FDR) নেই।
     */
    public function test_the_dps_schedule_knows_which_months_were_paid(): void
    {
        // ⓘ আজ মাসের ১০ তারিখ ধরে — কিস্তির দিন ৫, তাই এই মাসের কিস্তিও বকেয়া হতে পারে
        Carbon::setTestNow(now()->startOfMonth()->addDays(9));
        $m = fn (int $offset) => now()->startOfMonth()->addMonths($offset);

        $dps = $this->depositOn('DPS-S', 'DPS', $m(-3)->addDays(4), $m(20), instalment: '5000', day: 5);
        $this->moveOn($dps, DepositMovement::OPENED, '5000', $m(-3)->addDays(4), DocumentStatus::CONFIRMED);
        $this->moveOn($dps, DepositMovement::INSTALMENT, '5000', $m(-2)->addDays(6), DocumentStatus::DRAFT);
        $this->moveOn($dps, DepositMovement::INSTALMENT, '5000', $m(-1)->addDays(3), DocumentStatus::CANCELLED);
        $this->moveOn($dps, DepositMovement::INSTALMENT, '2000', $m(0)->addDays(2), null);

        // ⓘ মেয়াদ পেরোনো DPS — গত মাসে শেষ, এই মাস নেই
        $ended = $this->depositOn('DPS-T', 'DPS', $m(-2), $m(-1)->addDays(10), instalment: '1000', day: 1);
        $this->moveOn($ended, DepositMovement::OPENED, '1000', $m(-2), DocumentStatus::CONFIRMED);
        // ⓘ একই মাসে বেশি দেওয়া — বাকি শূন্য, ঋণাত্মক নয়, আর পরের মাস মাফ নয়
        $this->moveOn($ended, DepositMovement::INSTALMENT, '1500', $m(-2)->addDays(1), DocumentStatus::CONFIRMED);

        $result = app(ReportEngine::class)->run(DepositReports::INSTALMENTS, ['from' => $m(-6)->toDateString(), 'to' => now()->toDateString()]);
        $rows = array_map(fn ($r) => (array) $r, $result->rows);
        $s = array_values(array_filter($rows, fn ($r) => $r['document_no'] === 'DPS-S'));

        $this->assertSame([$m(-3)->toDateString(), $m(-2)->toDateString(), $m(-1)->toDateString(), $m(0)->toDateString()],
            array_map(fn ($r) => substr((string) $r['for_month'], 0, 10), $s), 'খোলার মাস থেকে এই মাস পর্যন্ত');
        $this->assertMoney('0', $s[0]['outstanding'], 'খোলার টাকা প্রথম মাসের কিস্তি');
        $this->assertMoney('0', $s[0]['waiting'], '⛔ খাতায় বসা কিস্তি সইয়ের অপেক্ষায় দেখাল');
        $this->assertMoney('5000', $s[1]['waiting'], 'সইয়ের অপেক্ষায়');
        $this->assertMoney('5000', $s[1]['overdue'], '⛔ সইয়ের অপেক্ষার কিস্তি দেওয়া ধরল');
        $this->assertMoney('0', $s[2]['paid'], '⛔ বাতিল কিস্তি দেওয়া ধরল');
        $this->assertMoney('5000', $s[2]['overdue'], 'বাতিলের মাস বকেয়া');
        $this->assertMoney('0', $s[2]['waiting'], '⛔ বাতিল কিস্তি সইয়ের অপেক্ষায় দেখাল');
        $this->assertMoney('2000', $s[3]['paid'], 'ভাউচার ছাড়া পুরনো কিস্তি — দেওয়া');
        $this->assertMoney('3000', $s[3]['overdue'], 'এই মাসের বাকি, কিস্তির দিন (৫) পেরিয়েছে');

        $t = array_values(array_filter($rows, fn ($r) => $r['document_no'] === 'DPS-T'));
        $this->assertCount(2, $t, '⛔ মেয়াদের পরের মাস দেখাল');
        $this->assertMoney('0', $t[0]['outstanding'], '⛔ বেশি দেওয়া মাসে বাকি ঋণাত্মক');
        $this->assertMoney('1000', $t[1]['overdue'], 'বেশি দেওয়ায় পরের মাস মাফ নয়');
        $this->assertSame([], array_values(array_filter($rows, fn ($r) => str_starts_with((string) $r['document_no'], 'FDR'))), '⛔ FDR কিস্তির তালিকায়');

        $summary = ($result->report->summary)($result->totals);
        $this->assertMoney('14000', $summary['value'], 'মোট বকেয়া = ৫০০০ + ৫০০০ + ৩০০০ + মেয়াদ পেরোনোর গত মাস ১০০০');
        $this->assertFalse($summary['good']);

        // ⓘ মাসের ২ তারিখ — কিস্তির দিনের আগে: এই মাস বাকি, বকেয়া নয়; ৩ তারিখের ২০০০ তখনো আসেনি
        Carbon::setTestNow(now()->startOfMonth()->addDays(1));
        $early = app(ReportEngine::class)->run(DepositReports::INSTALMENTS, ['from' => $m(0)->toDateString(), 'to' => now()->toDateString()]);
        $row = collect($early->rows)->map(fn ($r) => (array) $r)->firstWhere('document_no', 'DPS-S');
        $this->assertMoney('0', $row['paid'], '⛔ দিনের পরের কিস্তি দেওয়া ধরল');
        $this->assertMoney('5000', $row['outstanding'], 'বাকি');
        $this->assertMoney('0', $row['overdue'], '⛔ কিস্তির দিনের আগেই বকেয়া বলল');

        Carbon::setTestNow();
        $this->get(route('finance.deposit.report.show', ['slug' => 'instalments']))->assertOk()->assertSee('data-deposit-reports', false);
    }

    public function test_one_branch_shows_only_its_own(): void
    {
        $this->assertSame(['FDR-G'], array_column($this->accrued(0, ['branch_id' => $this->netrakona->id]), 'document_no'));
    }

    public function test_the_page_opens_from_the_deposit_list(): void
    {
        $this->get(route('finance.deposit.index', ['issuer' => DepositKind::BANK]))->assertOk()->assertSee('data-deposit-reports', false);
        $this->get(route('finance.deposit.report.show', ['slug' => 'accrued']))->assertOk()->assertSee('FDR-A');
        $this->get(route('finance.deposit.report.show', ['slug' => 'nothing']))->assertNotFound();
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    private function accrued(int $offset = 0, array $extra = []): array
    {
        $on = $this->today->copy()->addDays($offset)->toDateString();
        $result = app(ReportEngine::class)->run(DepositReports::ACCRUED, ['from' => $on, 'to' => $on, ...$extra]);

        return array_map(fn ($r) => (array) $r, $result->rows);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function find(array $rows, string $no): ?array
    {
        foreach ($rows as $row) {
            if ($row['document_no'] === $no) {
                return $row;
            }
        }

        return null;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function row(array $rows, string $no): array
    {
        $row = $this->find($rows, $no);
        $this->assertNotNull($row, "সারি নেই: {$no}");

        return $row;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function assertRow(array $rows, string $no, string $principal, string $accrued, string $tax, string $net): void
    {
        $row = $this->row($rows, $no);
        $this->assertMoney($principal, $row['principal'], "{$no} আসল");
        $this->assertMoney($accrued, $row['accrued'], "{$no} অর্জিত");
        $this->assertMoney($tax, $row['source_tax'], "{$no} উৎসে কর");
        $this->assertMoney($net, $row['net_accrued'], "{$no} নিট");
    }

    private function assertMoney(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, (string) ($actual ?? '0'), 2), "{$message}: চাই {$expected}, এল ".var_export($actual, true));
    }

    private function day(int $offset): string
    {
        return $this->today->copy()->addDays($offset)->toDateString();
    }

    private function deposit(string $no, string $kind, string $principal, ?string $rate, int $opened, int $matures,
        string $heldBy = Deposit::BUSINESS, string $status = Deposit::ACTIVE, ?int $closedOn = null, ?Branch $branch = null): Deposit
    {
        return Deposit::query()->create([
            'company_id' => CompanyContext::id(), 'branch_id' => ($branch ?? $this->mymensingh)->id,
            'document_no' => $no, 'kind_id' => DepositKind::query()->where('code', $kind)->value('id'),
            'institution' => 'সোনালী ব্যাংক', 'held_by' => $heldBy, 'principal' => $principal,
            'profit_rate' => $rate, 'tax_rate' => '10', 'return_word' => 'interest',
            'opened_on' => $this->day($opened), 'matures_on' => $this->day($matures),
            'account_id' => StandardChart::find($heldBy === Deposit::OWNER ? StandardChart::DRAWINGS : StandardChart::DEPOSITS_AND_INVESTMENTS)->id,
            'status' => $status, 'closed_on' => $closedOn === null ? null : $this->day($closedOn),
        ]);
    }

    private function move(Deposit $deposit, string $kind, string $amount, int $on, ?string $voucherStatus): void
    {
        $voucher = $voucherStatus === null ? null : Voucher::query()->create([
            'company_id' => $deposit->company_id, 'branch_id' => $deposit->branch_id,
            'financial_year_id' => FinancialYear::query()->withoutGlobalScopes()->where('company_id', $deposit->company_id)->value('id'),
            'type' => Voucher::PAYMENT, 'document_no' => 'PV-DEP-'.random_int(1, 999999), 'trx_date' => $this->day($on),
            'amount' => $amount, 'status' => $voucherStatus,
        ]);

        DepositMovement::query()->create([
            'company_id' => $deposit->company_id, 'deposit_id' => $deposit->id, 'kind' => $kind, 'amount' => $amount,
            'moved_on' => $this->day($on), 'voucher_id' => $voucher?->id,
        ]);
    }

    private function depositOn(string $no, string $kind, Carbon $opened, Carbon $matures, string $instalment, int $day): Deposit
    {
        return Deposit::query()->create([
            'company_id' => CompanyContext::id(), 'branch_id' => $this->mymensingh->id,
            'document_no' => $no, 'kind_id' => DepositKind::query()->where('code', $kind)->value('id'),
            'institution' => 'ডাচ্-বাংলা ব্যাংক', 'held_by' => Deposit::BUSINESS, 'principal' => '0',
            'profit_rate' => '7', 'tax_rate' => '10', 'return_word' => 'interest',
            'opened_on' => $opened->toDateString(), 'matures_on' => $matures->toDateString(),
            'instalment_amount' => $instalment, 'instalment_day' => $day,
            'account_id' => StandardChart::find(StandardChart::DEPOSITS_AND_INVESTMENTS)->id, 'status' => Deposit::ACTIVE,
        ]);
    }

    private function moveOn(Deposit $deposit, string $kind, string $amount, Carbon $on, ?string $voucherStatus): void
    {
        $this->move($deposit, $kind, $amount, (int) $this->today->diffInDays($on->copy()->startOfDay(), false), $voucherStatus);
    }
}
