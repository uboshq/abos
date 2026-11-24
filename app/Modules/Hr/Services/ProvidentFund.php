<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Services\SettingsService;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Models\SalaryStructure;
use App\Modules\Hr\Support\BranchReach;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ ভবিষ্য তহবিল — কর্মীর ভাগ, প্রতিষ্ঠানের সমান ভাগ, আর প্রতিজনের জমার খাতা (মালিকের অনুমোদিত আইনি বেতনের পরিকল্পনা, "plan onujayi
 * koro", a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── ⭐ আইন ও মান ────────────────────────────────────────────────────────
 * BLA ২০০৬ ধারা ২৬৪ আর শ্রম বিধিমালা ২০১৫ বিধি ২৬২: কর্মী মূল মজুরির ৭–৮% দেন, মালিক সমান হারে দেন, দুই ভাগই তহবিলে জমা।
 * IAS 19 ¶৫১ (নির্ধারিত-চাঁদার পরিকল্পনা): প্রতিষ্ঠানের ভাগ যে মাসের কাজ সেই মাসের খরচ, আর জমা না দেওয়া পর্যন্ত দায়।
 *
 * ── ⭐ খাতায় কী বসে ([[PayrollService::ledgerLines()]]) ─────────────────
 *  · কর্মীর ভাগ — আগের মতোই বেতনের কর্তন, ২১৩১ ক্রেডিট (খাতের নিজের হিসাব-খাত, [[SalaryHeadService::installDefaults()]])।
 *  · প্রতিষ্ঠানের ভাগ — বেতনের স্লিপে আলাদা সারি ([[PayslipLine::EMPLOYER]]), নিটের বাইরে: ৫২২৫ ডেবিট (খরচ), ২১৩১ ক্রেডিট (দায়)।
 *  ⓘ একই রানের একই দাখিলায়, একই পথে — সই, শাখা, উল্টানো সব বেতনের সাথে।
 *
 * ── ⛔ পুরনো কোম্পানির হার নীরবে বদলায় না ──────────────────────────────
 * কর্মীর ভাগের হার প্রতিজনের বেতন-কাঠামোয় (`PF` খাত, মূলের শতাংশ) — অনেকে আজ ১০% কাটেন। সুইচ চালু করলে সেটা ছোঁয়া হয় না:
 * [[proposal()]] প্রতিজনের আজকের আর প্রস্তাবিত (সেটিংয়ের ৮%) কাটা পাশাপাশি দেখায়, আর `hr.salary.manage` থাকা কেউ একটা তারিখ
 * বেছে "বসান" চাপলে তবে সেই তারিখ থেকে বসে ([[apply()]]) — বেতনের কাঠামোর মানুষের দরজা দিয়েই, তাই কোম্পানি দ্বিতীয় সই চালু
 * রাখলে সেটাও লাগে, আর প্রতিজনের অডিটে একটা সারি ([[RATE_APPLIED]])।
 *
 * ⛔ সুইচ বন্ধ (ডিফল্ট) মানে আগের অবিকল আচরণ: প্রতিষ্ঠানের ভাগ নেই, কোনো নতুন সারি নেই।
 */
final class ProvidentFund
{
    public const ENABLED = 'hr.pf.enabled';

    public const EMPLOYEE_PERCENT = 'hr.pf.employee_percent';

    public const EMPLOYER_PERCENT = 'hr.pf.employer_percent';

    /** ⓘ অডিটের চিহ্ন — প্রস্তাবিত হার বসানো হলো (কলাম ২৪ অক্ষরের) */
    public const RATE_APPLIED = 'pf_rate_applied';

    /** ⓘ অডিটের চিহ্ন — তহবিলে যোগ দিলেন */
    public const ENROLLED = 'pf_enrolled';

    /** অনুমোদনের অপেক্ষায় — বসলে সারি লেখে কাঠামো নিজে ([[SalaryStructureService::applySigned()]], `salary_change_signed`) */
    public const RATE_ASKED = 'pf_rate_asked';

    public const ENROL_ASKED = 'pf_enrol_asked';

    /** ⭐ প্রতিষ্ঠানের ভাগ প্রাপ্তির ধাপ (BLA ধারা ২৬৭) — খালি = বসানো নেই ([[vestingSteps()]]) */
    public const VESTING_STEPS = 'hr.pf.vesting_steps';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly SalaryStructureService $salaries,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::ENABLED);
    }

    /** কর্মীর ভাগের প্রস্তাবিত হার — মূলের শতাংশ (ডিফল্ট ৮) */
    public function employeePercent(): string
    {
        return self::percent($this->settings->get(self::EMPLOYEE_PERCENT));
    }

    /** প্রতিষ্ঠানের ভাগের হার — মূলের শতাংশ (ডিফল্ট ৮) */
    public function employerPercent(): string
    {
        return self::percent($this->settings->get(self::EMPLOYER_PERCENT));
    }

    /** তহবিলের দায় — ২১৩১ */
    public function fundAccount(): ?Account
    {
        return Account::query()->postable()->where('code', StandardChart::PROVIDENT_FUND_PAYABLE)->first();
    }

    /** প্রতিষ্ঠানের চাঁদার খরচ — ৫২২৫ */
    public function expenseAccount(): ?Account
    {
        return Account::query()->postable()->where('code', StandardChart::PF_EMPLOYER_EXPENSE)->first();
    }

    /**
     * ⭐ প্রতিষ্ঠানের ভাগ — মূলের নির্দিষ্ট শতাংশ, পয়সায় গোল (বেতনশিটের প্রতিটা সারির মতো একবারই, [[PayrollService::buildPayslip()]])।
     *
     * ⓘ ভিত দেওয়া মূল — স্লিপের মূল-সারি, মাসের কাজের দিনের ভাগে, পয়সায় গোল (fe, ২১ অক্টোবর ২০২৬, দফা ৪;
     * [[PayrollService::buildPayslip()]])। মাসের মাঝে যোগ দিলে প্রতিষ্ঠানের ভাগও সেই ভাগের।
     */
    public function employerShare(string $basic): string
    {
        return Money::round(bcdiv(bcmul($basic, $this->employerPercent(), 10), '100', 10), 2);
    }

    /**
     * ⓘ কর্মীর ভাগের খাত — চালু কর্তনের খাত, মূলের শতাংশ, যার হিসাব-খাত তহবিলের দায় (২১৩১); একাধিক হলে `PF` কোডেরটা আগে।
     */
    public function fundHead(): ?SalaryHead
    {
        $fund = $this->fundAccount();

        if ($fund === null) {
            return null;
        }

        return SalaryHead::query()->active()
            ->where('kind', SalaryHead::DEDUCTION)
            ->where('calculation', SalaryHead::PERCENT_OF_BASIC)
            ->where('account_id', $fund->id)
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', ['PF'])
            ->orderBy('id')
            ->first();
    }

    /**
     * ⭐ প্রস্তাবিত বদলের তালিকা — প্রতিজনের আজকের আর প্রস্তাবিত কর্মী-ভাগ, পাশাপাশি; কিছুই বসায় না।
     *
     * ⓘ সেই তারিখের মাসে বেতনের তালিকায় যাঁরা ([[Employee::scopeOnPayrollFor()]])। "সদস্য" = তহবিলের খাতে শূন্যের বেশি হার বসানো;
     * ⛔ সদস্য নন এমন কারও নামে বসানো হয় না — তহবিলে ঢোকানো কর্মীর নিজের সিদ্ধান্ত (BLA ধারা ২৬৪(৩)), নীরব বদল নয়।
     *
     * @return list<array{employee: Employee, member: bool, current_percent: ?string, current: string, proposed_percent: string,
     *     proposed: string, employer: string, changes: bool}>
     */
    public function proposal(Carbon $on, ?User $for = null): array
    {
        $head = $this->fundHead();

        if ($head === null) {
            return [];
        }

        $proposedPercent = $this->employeePercent();
        $rows = [];
        $staff = Employee::query()->onPayrollFor($on->copy()->endOfMonth());
        // ⓘ মানুষ ধরে চাইলে কেবল তাঁর দেখার শাখার কর্মী — পর্দায় যা দেখেন, "বসান" ঠিক তাতেই ([[BranchReach::employeesInView()]])
        $staff = $for === null ? $staff : app(BranchReach::class)->employeesInView($staff, $for);

        foreach ($staff->orderBy('code')->get() as $employee) {
            $basic = $this->basicOn($employee, $on);
            $row = SalaryStructure::query()
                ->where('employee_id', $employee->id)
                ->where('salary_head_id', $head->id)
                ->whereDate('effective_from', '<=', $on->toDateString())
                ->orderByDesc('effective_from')->orderByDesc('id')
                ->first();

            $currentPercent = $row === null ? null : bcadd((string) $row->amount, '0', 4);
            $member = $currentPercent !== null && bccomp($currentPercent, '0', 4) > 0;

            $rows[] = [
                'employee' => $employee,
                'member' => $member,
                'current_percent' => $currentPercent,
                'current' => $member ? Money::round(bcdiv(bcmul($basic, $currentPercent, 10), '100', 10), 2) : '0.00',
                'proposed_percent' => $proposedPercent,
                'proposed' => $member ? Money::round(bcdiv(bcmul($basic, $proposedPercent, 10), '100', 10), 2) : '0.00',
                'employer' => $member ? $this->employerShare($basic) : '0.00',
                'changes' => $member && bccomp($currentPercent, $proposedPercent, 4) !== 0,
            ];
        }

        return $rows;
    }

    /**
     * ⭐ প্রস্তাবিত হার বসানো — বেছে নেওয়া তারিখ থেকে, কেবল যে সদস্যদের হার আলাদা (a6, ২১ অক্টোবর ২০২৬)।
     *
     * ⓘ বেতনের কাঠামোর মানুষের দরজা দিয়েই ([[SalaryStructureService::change()]]): দেওয়া মাসে বসে না, আগের বদল সইয়ের অপেক্ষায়
     * থাকলে সেই কর্মী বাদ, আর কোম্পানির দ্বিতীয় সইয়ের ছক চালু থাকলে প্রতিজনের বদল "সইয়ের অপেক্ষায়" যায়। প্রতিজনের অডিটে
     * আগের আর নতুন হার আর তারিখ ([[RATE_APPLIED]]) — টাকার অঙ্ক নয়, তাই নিরীক্ষকের চোখে বেতন খোলে না।
     *
     * @return array{applied: int, waiting: int, skipped: list<array{employee: Employee, reason: string}>}
     *
     * @throws AuthorizationException `hr.salary.manage` না থাকলে
     */
    public function apply(Carbon $from, User $by): array
    {
        if (! $by->can('hr.salary.manage')) {
            throw new AuthorizationException(__('hr::validation.pf_apply_needs_the_key'));
        }

        $head = $this->fundHead();

        if ($head === null) {
            throw ValidationException::withMessages(['effective_from' => __('hr::validation.pf_head_missing')]);
        }

        $result = ['applied' => 0, 'waiting' => 0, 'skipped' => []];

        foreach ($this->proposal($from, $by) as $row) {
            if (! $row['changes']) {
                continue;
            }

            try {
                $waiting = $this->salaries->change($row['employee'], $from->toDateString(), [$head->id => $row['proposed_percent']]);
            } catch (ValidationException $e) {
                $result['skipped'][] = ['employee' => $row['employee'], 'reason' => (string) collect($e->errors())->flatten()->first()];

                continue;
            }

            // ⛔ অনুমোদনের অপেক্ষায় থাকলে "বসেছে" লেখা নয়
            $asked = $waiting instanceof Approval;
            $row['employee']->auditAction($asked ? self::RATE_ASKED : self::RATE_APPLIED, __($asked ? 'hr::message.pf_rate_asked' : 'hr::message.pf_rate_applied', [
                'from' => self::shown((string) $row['current_percent']),
                'to' => self::shown($row['proposed_percent']),
                'date' => $from->toDateString(),
            ]));

            $waiting instanceof Approval ? $result['waiting']++ : $result['applied']++;
        }

        return $result;
    }

    /**
     * ⭐ সদস্য নন, অথচ যোগ্য — সেই তারিখে অন্তত এক বছরের চাকরি (BLA ধারা ২৬৪: এক বছর পূর্ণ হলে তহবিলে যোগ দেওয়ার অধিকার; fe, ২১ অক্টোবর
     * ২০২৬, দফা ১)। ⓘ প্রস্তাবের পাতায় আলাদা তালিকা; ⛔ নিজে থেকে কাউকে তহবিলে ঢোকানো হয় না — যোগ দেওয়া আলাদা কাজ ([[enrol()]])।
     *
     * @return list<array{employee: Employee, proposed: string, employer: string}>
     */
    public function eligibleNonMembers(Carbon $on, ?User $for = null): array
    {
        $cutoff = $on->copy()->subYearNoOverflow();
        $rows = [];

        foreach ($this->proposal($on, $for) as $row) {
            $joined = $row['employee']->joining_date;

            if ($row['member'] || $joined === null || $joined->copy()->startOfDay()->gt($cutoff)) {
                continue;
            }

            $basic = $this->basicOn($row['employee'], $on);
            $rows[] = [
                'employee' => $row['employee'],
                'proposed' => Money::round(bcdiv(bcmul($basic, $this->employeePercent(), 10), '100', 10), 2),
                'employer' => $this->employerShare($basic),
            ];
        }

        return $rows;
    }

    /**
     * ⭐ তহবিলে যোগ — একজন, একটা তারিখ থেকে, প্রস্তাবিত হারে; বেতনের কাঠামোর মানুষের দরজা দিয়ে (দ্বিতীয় সই চালু থাকলে সেটাও লাগে)।
     *
     * ⛔ কেবল যোগ্য অ-সদস্য ([[eligibleNonMembers()]]); সদস্যের হার বদল [[apply()]]-এর কাজ। অডিটে `pf_enrolled`, টাকা ছাড়া।
     *
     * @throws AuthorizationException `hr.salary.manage` না থাকলে
     * @throws ValidationException যোগ্য নন, আগেই সদস্য, বা দেওয়া মাস
     */
    public function enrol(Employee $employee, Carbon $from, User $by): ?Approval
    {
        if (! $by->can('hr.salary.manage')) {
            throw new AuthorizationException(__('hr::validation.pf_apply_needs_the_key'));
        }

        $head = $this->fundHead();

        if ($head === null) {
            throw ValidationException::withMessages(['effective_from' => __('hr::validation.pf_head_missing')]);
        }

        $eligible = collect($this->eligibleNonMembers($from))->contains(fn (array $row) => (int) $row['employee']->id === (int) $employee->id);

        if (! $eligible) {
            throw ValidationException::withMessages(['employee' => __('hr::validation.pf_not_eligible')]);
        }

        $waiting = $this->salaries->change($employee, $from->toDateString(), [$head->id => $this->employeePercent()]);

        $employee->auditAction($waiting === null ? self::ENROLLED : self::ENROL_ASKED, __($waiting === null ? 'hr::message.pf_enrolled' : 'hr::message.pf_enrol_asked', [
            'rate' => self::shown($this->employeePercent()),
            'date' => $from->toDateString(),
        ]));

        return $waiting;
    }

    /**
     * ⭐ প্রতিষ্ঠানের জমা ভাগের কতটা কর্মীর প্রাপ্য — চাকরির পূর্ণ বছর ধরে (BLA ধারা ২৬৭; fe, ২১ অক্টোবর ২০২৬, দফা ১)। খাঁটি হিসাব —
     * কিছু পড়ে না, কিছু লেখে না।
     *
     * ⓘ ধাপ = [[vestingSteps()]]: "পূর্ণ বছর:শতাংশ", ছোট থেকে বড়। প্রাপ্য = সবচেয়ে বড় যে ধাপের বছর ≤ পূর্ণ বছর, তার শতাংশ; প্রথম ধাপের
     * কম হলে শূন্য। পয়সায় একবার গোল।
     *
     * @param  list<array{years: int, percent: string}>  $steps
     */
    public static function vest(string $employerTotal, int $completedYears, array $steps): string
    {
        $percent = '0';

        foreach ($steps as $step) {
            if ($completedYears >= $step['years']) {
                $percent = $step['percent'];
            }
        }

        return Money::round(bcdiv(bcmul($employerTotal, $percent, 10), '100', 10), 2);
    }

    /**
     * ⭐ সেটিংয়ের ধাপ — `hr.pf.vesting_steps`, যেমন "3:50, 5:100"।
     *
     * ⛔ খালি বা পড়া না গেলে `null` — মানে "বসানো নেই", ১০০% নয়। চূড়ান্ত নিষ্পত্তি (আসছে) তখন প্রতিষ্ঠানের ভাগের হিসাব করতে রাজি হবে না
     * — কোম্পানির তহবিলের বিধি না জেনে টাকা দেওয়া যায় না; কোডে কোনো সংখ্যা বসানো নেই, কারণ বিধি তহবিলের নিজের।
     *
     * @return list<array{years: int, percent: string}>|null
     */
    public function vestingSteps(): ?array
    {
        $raw = trim((string) $this->settings->get(self::VESTING_STEPS));

        if ($raw === '') {
            return null;
        }

        $steps = [];

        foreach (preg_split('/[,;\n]+/', $raw) ?: [] as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (! preg_match('/^(\d{1,2})\s*:\s*(\d{1,3}(?:\.\d{1,4})?)$/', $part, $m) || bccomp($m[2], '100', 4) > 0) {
                return null;
            }

            $steps[] = ['years' => (int) $m[1], 'percent' => bcadd($m[2], '0', 4)];
        }

        usort($steps, fn (array $a, array $b) => $a['years'] <=> $b['years']);

        return $steps === [] ? null : $steps;
    }

    /** ⓘ প্রাপ্য প্রতিষ্ঠানের ভাগ — ধাপ বসানো না থাকলে `null` (চূড়ান্ত নিষ্পত্তি থামবে) */
    public function vestedEmployerShare(string $employerTotal, int $completedYears): ?string
    {
        $steps = $this->vestingSteps();

        return $steps === null ? null : self::vest($employerTotal, $completedYears, $steps);
    }

    /**
     * ⭐ তহবিলে জমা — কর্মী আর মাস ধরে, কেবল নিশ্চিত রানের বেতনের স্লিপ থেকে (খাতায় যা বসেছে ঠিক সেটুকু)।
     *
     * ⓘ কর্মীর ভাগ = তহবিলের দায়ে (২১৩১) বসা কর্তনের সারি; প্রতিষ্ঠানের ভাগ = [[PayslipLine::EMPLOYER]] সারি। ⓘ রিপোর্ট
     * ([[HrReports::providentFund()]]) আর কর্মীর পাতা ([[ledgerOf()]]) দুটোই এই এক প্রশ্ন পড়ে।
     */
    public function contributions(): Builder
    {
        $fund = $this->fundAccount()?->id;

        return PayslipLine::query()->toBase()
            ->join('hr_payslips as s', 's.id', '=', 'hr_payslip_lines.payslip_id')
            ->join('hr_payroll_runs as r', 'r.id', '=', 's.payroll_run_id')
            ->where('r.status', DocumentStatus::CONFIRMED)
            ->whereNull('r.deleted_at')
            ->whereNull('s.deleted_at')
            ->where(fn ($q) => $q->where('hr_payslip_lines.kind', PayslipLine::EMPLOYER)
                ->when($fund !== null, fn ($w) => $w->orWhere(fn ($d) => $d->where('hr_payslip_lines.kind', SalaryHead::DEDUCTION)
                    ->where('hr_payslip_lines.account_id', $fund))))
            ->groupBy('s.employee_id', 'r.month')
            ->select(['s.employee_id', 'r.month'])
            ->selectRaw('SUM(CASE WHEN hr_payslip_lines.kind = ? THEN hr_payslip_lines.amount ELSE 0 END) as employee_share', [SalaryHead::DEDUCTION])
            ->selectRaw('SUM(CASE WHEN hr_payslip_lines.kind = ? THEN hr_payslip_lines.amount ELSE 0 END) as employer_share', [PayslipLine::EMPLOYER]);
    }

    /**
     * ⭐ একজনের তহবিলের খাতা — মাস ধরে, কর্মীর ভাগ, প্রতিষ্ঠানের ভাগ আর চলতি জের (কর্মীর পাতা, বেতনের চাবিতে)।
     *
     * @return list<array{month: Carbon, employee_share: string, employer_share: string, balance: string}>
     */
    public function ledgerOf(Employee $employee): array
    {
        $balance = '0';
        $rows = [];

        foreach ($this->contributions()->where('s.employee_id', $employee->id)->orderBy('r.month')->get() as $row) {
            $employeeShare = bcadd((string) $row->employee_share, '0', 4);
            $employerShare = bcadd((string) $row->employer_share, '0', 4);
            $balance = bcadd($balance, bcadd($employeeShare, $employerShare, 4), 4);

            $rows[] = [
                'month' => Carbon::parse((string) $row->month)->startOfMonth(),
                'employee_share' => $employeeShare,
                'employer_share' => $employerShare,
                'balance' => $balance,
            ];
        }

        return $rows;
    }

    /** কাঠামোর মূল — অনুপস্থিতির ভাগ ছাড়া */
    private function basicOn(Employee $employee, Carbon $on): string
    {
        foreach ($this->salaries->componentsOn($employee, $on) as $component) {
            if ($component['head']->is_basic) {
                return (string) $component['amount'];
            }
        }

        return '0';
    }

    /** ⓘ সেটিংয়ের হার — ০ থেকে ১০০-র সাধারণ সংখ্যা; নইলে শূন্য (ভুল লেখায় কারও বেতন থেকে অচেনা অঙ্ক কাটে না) */
    private static function percent(mixed $value): string
    {
        $value = trim((string) $value);

        if (! preg_match('/^\d{1,3}(\.\d{1,4})?$/', $value) || bccomp($value, '100', 4) > 0) {
            return '0';
        }

        return bcadd($value, '0', 4);
    }

    /** ⓘ অডিটের লেখায় হার — "10.0000" নয়, "10" */
    private static function shown(string $percent): string
    {
        if ($percent === '') {
            return '0';
        }

        return rtrim(rtrim(bcadd($percent, '0', 4), '0'), '.');
    }
}
