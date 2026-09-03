<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\FinancialYear;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * এক মাসের বেতন — তৈরি, নিশ্চিতকরণ, বাতিল।
 *
 * ── প্ল্যানের মাপকাঠি ────────────────────────────────────────────────
 * "বেতন খাতায় বসে, ব্যাংক ফাইল বের হয়।" তাই এই সেবার দুইটা কাজ:
 * প্রতিটা কর্মীর অঙ্ক শিটে বসানো, আর নিশ্চিত করার দিনে সেটা হিসাবের
 * বইয়ে তোলা।
 *
 * ── কেন অঙ্কগুলো কপি হয়ে বসে ─────────────────────────────────────────
 * শিট বানানোর সময় কাঠামো থেকে হিসাব হয়, তারপর অঙ্কটা শিটেই লেখা থাকে।
 * প্রতিবার নতুন করে হিসাব করলে কাঠামো শুধরানোর দিনে গত মাসের শিটও
 * বদলে যেত — অথচ ব্যাংকে অন্য টাকা গেছে।
 */
final class PayrollService
{
    public function __construct(
        private readonly SalaryStructureService $salaries,
        private readonly AttendanceService $attendance,
        private readonly SettingsService $settings,
        private readonly PostingEngine $posting,
        private readonly NumberSeriesEngine $numbers,
        private readonly DocumentApproval $approvals,
    ) {}

    /**
     * একটা মাসের খসড়া রান বানানো।
     *
     * @param  string  $month  মাসের যেকোনো দিন — ভেতরে প্রথম দিনে নামানো হয়
     */
    public function build(string $month, ?string $trxDate = null): PayrollRun
    {
        $monthStart = Carbon::parse($month)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $trx = $trxDate === null ? $monthEnd->copy() : Carbon::parse($trxDate);

        $this->assertNoLiveRun($monthStart);

        /*
         * পুরো কোম্পানির কর্মী, শাখা ধরে ভাগ নয়।
         *
         * ── কেন শাখা এখানে ছাঁকনি নয় ────────────────────────────────
         * রানে শাখা লেখা থাকে, কিন্তু সেটা কেবল "কোথায় বসে করা হয়েছে"
         * তার ছাপ — বাকি প্রতিটা ডকুমেন্টের মতোই।
         *
         * ছাঁকনি বানালে দুইটা বিপদ একসাথে আসত: যে কর্মীর কোনো শাখা
         * বসানো নেই তিনি কোনো রানেই পড়তেন না, আর দুই শাখা আলাদা রান
         * করলে শাখাহীন কর্মীদের বেতন দুইবার হত।
         */
        $employees = Employee::query()
            ->onPayrollFor($monthEnd)
            ->orderBy('code')
            ->get();

        if ($employees->isEmpty()) {
            throw ValidationException::withMessages([
                'month' => __('hr::validation.nobody_on_payroll'),
            ]);
        }

        return DB::transaction(function () use ($employees, $monthStart, $monthEnd, $trx) {
            $run = PayrollRun::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'financial_year_id' => $this->financialYear($trx)?->id,
                'document_no' => $this->numbers->next('PRL'),
                'month' => $monthStart->toDateString(),
                'trx_date' => $trx->toDateString(),
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            foreach ($employees as $employee) {
                $this->buildPayslip($run, $employee, $monthEnd);
            }

            return $this->recount($run);
        });
    }

    /**
     * খসড়াটা আবার বানানো — কাঠামো শুধরানোর পর।
     *
     * পুরনো শিটগুলো মুছে নতুন করে বসে। নিশ্চিত করা রানে এটা চলে না:
     * তখন অঙ্কগুলো খাতায় বসে গেছে, আর খাতা বদলাতে হলে বিপরীত এন্ট্রি
     * লাগে — নীরব পুনর্গণনা নয়।
     */
    public function rebuild(PayrollRun $run): PayrollRun
    {
        $this->assertDraft($run);

        return DB::transaction(function () use ($run) {
            $monthEnd = $run->month->copy()->endOfMonth();

            $run->payslips()->each(function (Payslip $slip) {
                $slip->lines()->delete();
                $slip->forceDelete();
            });

            // build()-এর হুবহু একই পরিধি — নাহলে "আবার বানান" চাপলে
            // তালিকা বদলে যেত, আর কেউ নীরবে বাদ পড়ত
            $employees = Employee::query()
                ->onPayrollFor($monthEnd)
                ->orderBy('code')
                ->get();

            foreach ($employees as $employee) {
                $this->buildPayslip($run, $employee, $monthEnd);
            }

            return $this->recount($run);
        });
    }

    /**
     * নিশ্চিত করা — এখানেই বেতন খাতায় বসে।
     *
     * ── কোন দিকে কী বসে ─────────────────────────────────────────────
     * প্রতিটা আয়ের খাত ডেবিট (খরচ বাড়ল), প্রতিটা কর্তন ক্রেডিট (দায়
     * বাড়ল বা অগ্রিম কমল), আর হাতে যা থাকে সেটা "প্রদেয় বেতন"-এ ক্রেডিট।
     *
     * টাকা তখনো যায়নি — যাবে ভাউচারে, প্রদেয় বেতন ডেবিট করে। দুইটা
     * ধাপ আলাদা, কারণ বেতন হিসাব করা আর বেতন দেওয়া একই দিনে নাও হতে
     * পারে, আর হলেও একই কাজ নয়।
     */
    public function confirm(PayrollRun $run): PayrollRun
    {
        $this->assertDraft($run);

        $run->load('payslips.lines');

        if ($run->payslips->isEmpty()) {
            throw ValidationException::withMessages([
                'status' => __('hr::validation.nothing_to_confirm'),
            ]);
        }

        /*
         * সই আগে, খতিয়ান পরে।
         *
         * অঙ্কটা `net_total` — মোট বেতন নয়, হাতে যা যাবে সেটা। ছকের
         * সীমাটা মানুষ ওই সংখ্যাটা ধরেই ভাবেন ("দুই লাখের উপরে হলে
         * আমাকে জিজ্ঞেস কোরো"), আর কর্তনের আগের সংখ্যাটা সবসময় বড় বলে
         * সীমাটা নীরবে কড়া হয়ে যেত।
         */
        $this->approvals->assertClear(
            document: $run,
            module: 'hr',
            action: 'payroll',
            field: 'status',
            amount: (string) $run->net_total,
            reason: $run->narration,
        );

        return DB::transaction(function () use ($run) {
            $lines = $this->ledgerLines($run);

            $this->posting->post(
                sourceType: PayrollRun::SOURCE_TYPE,
                sourceId: $run->id,
                trxDate: $run->trx_date,
                lines: $lines,
                documentNo: $run->document_no,
                branchId: $run->branch_id,
            );

            $run->forceFill(['status' => DocumentStatus::CONFIRMED])->save();

            return $run->fresh();
        });
    }

    /**
     * বাতিল — খাতায় বসে গিয়ে থাকলে বিপরীত এন্ট্রি সহ।
     *
     * পুরনো এন্ট্রি মোছা হয় না। মুছলে ট্রায়াল ব্যালেন্স মিললেও "কী
     * হয়েছিল" প্রশ্নের উত্তর হারাত, আর নিরীক্ষায় একটা ফাঁক থাকত।
     */
    public function cancel(PayrollRun $run, string $reason): PayrollRun
    {
        if ($run->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('hr::validation.already_cancelled'),
            ]);
        }

        return DB::transaction(function () use ($run, $reason) {
            if ($run->status === DocumentStatus::CONFIRMED) {
                $this->posting->reverse(
                    sourceType: PayrollRun::SOURCE_TYPE,
                    sourceId: $run->id,
                    reversalDate: now(),
                    reason: $reason,
                );
            }

            $run->forceFill([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();

            return $run->fresh();
        });
    }

    /**
     * ব্যাংকে পাঠানোর ফাইল — যাদের বেতন ব্যাংকে যায় তাদের সারি।
     *
     * ── কেন CSV, আর কেন শুধু ব্যাংকের সারি ──────────────────────────
     * প্রতিটা ব্যাংকের নিজের ছক আছে, কিন্তু সবাই একই চারটা জিনিস চায়:
     * হিসাব নম্বর, নাম, অঙ্ক, আর শাখা চেনানোর নম্বর। CSV সব ব্যাংকের
     * পোর্টালেই তোলা যায়, আর দরকারে এক্সেলে খুলে ঠিক করা যায়।
     *
     * নগদ ও MFS-এর সারি বাদ: ব্যাংক সেগুলো চেনে না, আর ফাইলে থাকলে
     * পুরো ফাইলটাই প্রত্যাখ্যাত হত।
     *
     * @return array{name: string, content: string, rows: int}
     */
    public function bankFile(PayrollRun $run): array
    {
        $slips = $run->payslips()
            ->with('employee')
            ->where('payment_method', 'bank')
            ->get();

        $rows = [];
        $rows[] = ['Account No', 'Account Name', 'Amount', 'Routing No', 'Bank', 'Employee Code', 'Reference'];

        $reference = $run->document_no.' '.$run->month->format('M Y');

        foreach ($slips as $slip) {
            /*
             * শূন্য বা ঋণাত্মক নিট বাদ।
             *
             * কারও কর্তন আয়ের চেয়ে বেশি হলে নিট শূন্যের নিচে নামে (পুরো
             * অগ্রিম এক মাসেই কাটা)। ব্যাংককে ঋণাত্মক অঙ্ক পাঠানো যায় না,
             * আর শূন্য পাঠানোরও মানে নেই।
             */
            if (bccomp((string) $slip->net, '0', 4) <= 0) {
                continue;
            }

            $rows[] = [
                (string) $slip->bank_account_no,
                (string) ($slip->bank_account_name ?: $slip->employee?->name_en),
                // ব্যাংকের ফাইলে যাওয়া অঙ্ক — গোল করা bcmath-এ, কমা ছাড়া
                Money::round($slip->net, 2),
                (string) $slip->bank_routing_no,
                (string) $slip->bank_name,
                (string) $slip->employee?->code,
                $reference,
            ];
        }

        $csv = '';

        foreach ($rows as $row) {
            $csv .= implode(',', array_map($this->csvField(...), $row))."\r\n";
        }

        return [
            'name' => 'salary-'.$run->month->format('Y-m').'-'.$run->document_no.'.csv',
            'content' => $csv,
            // শিরোনামের সারিটা বাদ দিয়ে গোনা
            'rows' => max(0, count($rows) - 1),
        ];
    }

    // ── ভেতরের কাজ ────────────────────────────────────────────────────

    private function buildPayslip(PayrollRun $run, Employee $employee, Carbon $monthEnd): Payslip
    {
        $components = $this->salaries->componentsOn($employee, $monthEnd);

        /*
         * অনুপস্থিতির ভাগ — যতটুকু কাটার কথা ততটুকুই।
         *
         * ── কেন সব খাতে নয় ────────────────────────────────────────
         * তিন দিন কামাই করলে বেতন ও ভাতা কমে, কিন্তু অগ্রিমের কিস্তি
         * কমে না — ধার তো পুরোটাই নেওয়া হয়েছিল। কোন খাত কমবে তা
         * খাতের নিজের ঘরে (prorated_by_attendance) লেখা আছে।
         *
         * সুইচ বন্ধ থাকলে বা হাজিরাই না বসানো থাকলে ভাগটা ১ — কারও
         * বেতন কাটে না।
         */
        $factor = $this->attendanceFactor($employee, $monthEnd);

        $slip = Payslip::create([
            'company_id' => $run->company_id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'payment_method' => $employee->payment_method,
            'bank_name' => $employee->bank_name,
            'bank_account_name' => $employee->bank_account_name,
            'bank_account_no' => $employee->bank_account_no,
            'bank_routing_no' => $employee->bank_routing_no,
            'mfs_number' => $employee->mfs_number,
        ]);

        $gross = '0';
        $deductions = '0';

        foreach ($components as $component) {
            /** @var SalaryHead $head */
            $head = $component['head'];

            $amount = $head->prorated_by_attendance
                ? bcmul($component['amount'], $factor, 4)
                : $component['amount'];

            PayslipLine::create([
                'company_id' => $run->company_id,
                'payslip_id' => $slip->id,
                'salary_head_id' => $head->id,
                'head_code' => $head->code,
                'head_name_en' => $head->name_en,
                'head_name_bn' => $head->name_bn,
                'kind' => $head->kind,
                'amount' => $amount,
                'sort_order' => $head->sort_order,
                'account_id' => $this->accountFor($head)?->id,
            ]);

            if ($head->isEarning()) {
                $gross = bcadd($gross, $amount, 4);
            } else {
                $deductions = bcadd($deductions, $amount, 4);
            }
        }

        $slip->forceFill([
            'gross' => $gross,
            'deductions' => $deductions,
            'net' => bcsub($gross, $deductions, 4),
        ])->save();

        return $slip->fresh();
    }

    /**
     * মাসের কত ভাগ বেতন প্রাপ্য — ১ মানে পুরোটা।
     *
     * ── কেন মাসের দিন দিয়ে ভাগ, ২৬ বা ৩০ দিয়ে নয় ─────────────────
     * ফেব্রুয়ারিতে ২৮ দিন আর জুলাইয়ে ৩১। স্থির ৩০ ধরলে ফেব্রুয়ারিতে
     * এক দিন কামাই করলে মাসের ১/৩০ কাটত, অথচ মাসটাই ২৮ দিনের —
     * অর্থাৎ কম কাটত। ছোট পার্থক্য, কিন্তু প্রতি মাসে, প্রতিজনের।
     *
     * ── কেন হাজিরা না বসালে ভাগটা ১ ────────────────────────────────
     * খালি খাতাকে অনুপস্থিতি ধরলে সুইচ চালু করার প্রথম মাসেই সবার
     * বেতন শূন্য হত।
     */
    private function attendanceFactor(Employee $employee, Carbon $monthEnd): string
    {
        if (! $this->settings->get('hr.attendance_affects_salary')) {
            return '1';
        }

        $unpaid = $this->attendance->unpaidDays($employee, $monthEnd);

        if (bccomp($unpaid, '0', 1) <= 0) {
            return '1';
        }

        $daysInMonth = (string) $monthEnd->daysInMonth;
        $paid = bcsub($daysInMonth, $unpaid, 1);

        // পুরো মাস অনুপস্থিত থাকলে ভাগটা শূন্য, ঋণাত্মক নয়
        if (bccomp($paid, '0', 1) <= 0) {
            return '0';
        }

        return bcdiv($paid, $daysInMonth, 6);
    }

    /**
     * খাতাওয়ালা সারিগুলো — খাত ধরে জড়ো করা।
     *
     * ── কেন কর্মী ধরে ধরে নয় ────────────────────────────────────────
     * বিশ জন কর্মীর সাতটা খাত মানে ১৪০টা লেজার সারি, অথচ খাত ধরে
     * জড়ো করলে আটটা। খতিয়ানে "বেতন ও মজুরি" খুললে মাসে একটা সারি
     * দেখা যায়, আর কার কত তা বেতনশিটেই আছে — ড্রিল-ডাউন সেখানেই নেয়।
     *
     * @return list<array{account_id: int, debit?: string, credit?: string, narration?: string}>
     */
    private function ledgerLines(PayrollRun $run): array
    {
        $debits = [];
        $credits = [];
        $net = '0';

        foreach ($run->payslips as $slip) {
            $net = bcadd($net, (string) $slip->net, 4);

            foreach ($slip->lines as $line) {
                $accountId = $line->account_id ?? $this->fallbackAccount($line->kind)?->id;

                if ($accountId === null) {
                    throw ValidationException::withMessages([
                        'account' => __('hr::validation.head_needs_an_account', ['head' => $line->head_code]),
                    ]);
                }

                $bucket = $line->isEarning() ? 'debits' : 'credits';
                ${$bucket}[$accountId] = bcadd(${$bucket}[$accountId] ?? '0', (string) $line->amount, 4);
            }
        }

        $payable = $this->accountByCode(StandardChart::SALARY_PAYABLE);

        if ($payable === null) {
            throw ValidationException::withMessages([
                'account' => __('hr::validation.salary_payable_missing'),
            ]);
        }

        $narration = __('hr::message.ledger_narration', [
            'month' => $run->month->format('M Y'),
            'no' => $run->document_no,
        ]);

        $lines = [];

        foreach ($debits as $accountId => $amount) {
            if (bccomp($amount, '0', 4) === 0) {
                continue;
            }

            $lines[] = ['account_id' => (int) $accountId, 'debit' => $amount, 'narration' => $narration];
        }

        /*
         * নিট অঙ্কটাও একই ঝুড়িতে, আলাদা সারি নয়।
         *
         * যে খাতে নিজের হিসাব-খাত বসানো নেই তার কর্তনও "প্রদেয় বেতন"-এ
         * পড়ে। আলাদা করে যোগ করলে এক ডকুমেন্টে একই খাতে দুইটা ক্রেডিট
         * সারি বসত — খতিয়ানে দেখতে যেন দুইবার কিছু হয়েছে, অথচ হয়নি।
         */
        if (bccomp($net, '0', 4) !== 0) {
            $credits[$payable->id] = bcadd($credits[$payable->id] ?? '0', $net, 4);
        }

        foreach ($credits as $accountId => $amount) {
            if (bccomp($amount, '0', 4) === 0) {
                continue;
            }

            $lines[] = ['account_id' => (int) $accountId, 'credit' => $amount, 'narration' => $narration];
        }

        return $lines;
    }

    /**
     * একটা খাতের হিসাব-খাত — নিজেরটা, নাহলে প্রমিতটা।
     */
    private function accountFor(SalaryHead $head): ?Account
    {
        if ($head->account_id !== null) {
            return $head->account;
        }

        return $this->fallbackAccount($head->kind);
    }

    /**
     * খাত না বসানো থাকলে যেখানে যাবে।
     *
     * আয় যায় "বেতন ও মজুরি" খরচে, আর কর্তন যায় "প্রদেয় বেতন" দায়ে —
     * কারণ কেটে রাখা টাকাটা এখনো কর্মীরই, শুধু হাতে যায়নি। যে
     * প্রতিষ্ঠান ভবিষ্য তহবিল আলাদা রাখতে চায় সে খাতে নিজের হিসাব খাত
     * বসিয়ে দেবে, আর তখন এই ফলব্যাকটা আর লাগে না।
     */
    private function fallbackAccount(string $kind): ?Account
    {
        return $kind === SalaryHead::EARNING
            ? $this->accountByCode(StandardChart::SALARY_EXPENSE)
            : $this->accountByCode(StandardChart::SALARY_PAYABLE);
    }

    private function accountByCode(string $code): ?Account
    {
        return Account::query()->where('code', $code)->first();
    }

    private function recount(PayrollRun $run): PayrollRun
    {
        $slips = $run->payslips()->get();

        $run->forceFill([
            'gross_total' => $slips->reduce(fn ($c, $s) => bcadd($c, (string) $s->gross, 4), '0'),
            'deduction_total' => $slips->reduce(fn ($c, $s) => bcadd($c, (string) $s->deductions, 4), '0'),
            'net_total' => $slips->reduce(fn ($c, $s) => bcadd($c, (string) $s->net, 4), '0'),
            'employee_count' => $slips->count(),
        ])->save();

        return $run->fresh();
    }

    /**
     * এক মাসে একটাই জীবিত রান, পুরো কোম্পানিতে।
     *
     * দুইটা থাকলে একই মাসের বেতন দুইবার খরচে বসত, আর কেউ খেয়াল না
     * করলে ব্যাংকেও দুইবার টাকা যেত।
     *
     * বাতিল করাগুলো বাদ — নাহলে ভুল রান বাতিল করে আর নতুন বানানো যেত না।
     */
    private function assertNoLiveRun(Carbon $monthStart): void
    {
        $exists = PayrollRun::query()
            ->forMonth($monthStart->toDateString())
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'month' => __('hr::validation.month_already_run', [
                    'month' => $monthStart->format('M Y'),
                ]),
            ]);
        }
    }

    private function assertDraft(PayrollRun $run): void
    {
        if ($run->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('hr::validation.only_a_draft_can_change'),
            ]);
        }
    }

    private function financialYear(Carbon $date): ?FinancialYear
    {
        return FinancialYear::query()
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->first();
    }

    /** CSV-র একটা ঘর — কমা বা উদ্ধৃতি থাকলে মুড়ে দেওয়া। */
    private function csvField(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
