<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\FinalSettlement;
use App\Modules\Hr\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ চূড়ান্ত পাওনা — চাকরি ছাড়া কর্মীর এক কাগজ, মালিকের অনুমোদনে (মালিক, ২১ অক্টোবর ২০২৬: "plan onujayi koro")।
 *
 * ── ⭐ কী কী গোনা হয় ([[compute()]]) — চাকরির শেষ দিনে (`leaving_date`) ─────────────────────────────
 *   বকেয়া মজুরি    = শেষ নিশ্চিত বেতনের রানের মাসের পরের দিন থেকে শেষ দিন পর্যন্ত দিন × (মাসিক মোট আয় ÷ 30)
 *   অর্জিত ছুটির টাকা = না-নেওয়া অর্জিত ছুটি × মজুরি ÷ 30 (BLA ধারা 11; [[EarnedLeave::encashment()]])
 *   গ্র্যাচুইটি       = সেদিনের দায় (ধারা 2(10); [[Gratuity::obligation()]]) — গ্র্যাচুইটি চালু থাকলে
 *   ভবিষ্য তহবিল    = কর্মীর নিজের জমা + প্রাপ্য প্রতিষ্ঠানের ভাগ (ধারা 264–267; [[ProvidentFund::vestedEmployerShare()]])
 *   অগ্রিমের কর্তন    = কর্মীর অগ্রিমের খাতে (1131) বাকি — পাওনার বেশি নয়; বাকিটা অগ্রিম হয়েই থাকে
 *   নিট            = পাওনা − কর্তন
 * ⛔ তহবিলের সদস্যের প্রতিষ্ঠানের ভাগ আছে অথচ প্রাপ্যতার ধাপ (`hr.pf.vesting_steps`) বসানো নেই — কাগজ হয় না: তহবিলের বিধি না জেনে
 *   টাকা দেওয়া যায় না, আর কোডে কোনো সংখ্যা বসানো নেই।
 *
 * ── ⭐ অনুমোদন — সবসময় মালিকের ──────────────────────────────────────────
 * `hr` · `final_settlement`-এর `second_hand` চাবি `hr.final_settlement.approve` কোনো রোলে ডিফল্টে নেই — তাই কোম্পানির নিজের ছক না
 * থাকলে (বা সব ছক বন্ধ থাকলেও, UB) সই দেন কেবল মালিক (সুপার অ্যাডমিন); যিনি কাগজ বানালেন তিনি নন, মালিক নিজে বানালে নিজেই
 * অনুমোদন দেন, স্থায়ী দাগসহ ([[OwnerSelfApproval]])। ⛔ কোনো নিয়ম না ধরলে কাগজ হয় না (fail-closed)।
 *
 * ── ⭐ খাতায় — কেবল শেষ অনুমোদনে ([[finishSigned()]]) ──────────────────────
 *   Dr 5201 বেতন খরচ (মজুরি + ছুটির টাকা) · Dr 2134 গ্র্যাচুইটির দায় · Dr 2131 ভবিষ্য তহবিল
 *   Cr 1131 কর্মীর অগ্রিম (কর্মীর নামে) · Cr 2130 প্রদেয় বেতন (কর্মীর নামে) — নিট, পরে সাধারণ পরিশোধের ভাউচারে যায়
 * ⓘ গ্র্যাচুইটির মাসের সঞ্চিতি এই কাগজে মেটানো অঙ্ক বাদ দিয়ে মেলায় ([[Gratuity::accrue()]]) — নাহলে একই দায় দুইবার ছাড়া হত।
 */
final class FinalSettlements
{
    public const MODULE = 'hr';

    public const ACTION = 'final_settlement';

    public const SOURCE_TYPE = 'final_settlement';

    /** ⓘ দৈনিক হার = মাসিক ÷ 30 — ছুটির টাকা আর গ্র্যাচুইটির একই ভাজক */
    public const DAY_DIVISOR = 30;

    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly EarnedLeave $leave,
        private readonly Gratuity $gratuity,
        private readonly ProvidentFund $fund,
        private readonly SalaryStructureService $salaries,
        private readonly PostingEngine $posting,
    ) {}

    /**
     * ⭐ শেষ দিনের হিসাব — কিছু লেখে না (পর্দার আগাম দেখা আর কাগজ, দুটোই এটা পড়ে)।
     *
     * @return array{last_day: string, wages: string, leave_encashment: string, gratuity: string, provident_fund: string,
     *               advance_recovered: string, earnings: string, net: string, details: array<string, mixed>}
     *
     * @throws ValidationException শেষ দিন বসানো নেই, বা তহবিলের প্রাপ্যতার ধাপ বসানো নেই
     */
    public function compute(Employee $employee): array
    {
        if ($employee->leaving_date === null) {
            throw ValidationException::withMessages(['employee' => __('hr::settlement.no_last_day')]);
        }

        $lastDay = $employee->leaving_date->copy()->startOfDay();

        // ── বকেয়া মজুরি
        $from = $this->unpaidFrom($employee);
        $days = $from->gt($lastDay) ? 0 : (int) $from->diffInDays($lastDay) + 1;
        $gross = '0';
        foreach ($this->salaries->componentsOn($employee, $lastDay) as $component) {
            if ($component['head']->isEarning()) {
                $gross = bcadd($gross, (string) $component['amount'], 4);
            }
        }
        $wages = $days === 0 ? '0.00' : Money::round(bcdiv(bcmul($gross, (string) $days, 10), (string) self::DAY_DIVISOR, 10), 2);

        // ── অর্জিত ছুটি
        $leave = $this->leave->encashment($employee, $lastDay);

        // ── গ্র্যাচুইটি
        $gratuity = $this->gratuity->enabled()
            ? $this->gratuity->obligation($employee, $lastDay)
            : ['years' => 0, 'days' => 0, 'basic' => '0', 'obligation' => '0.00'];

        // ── ভবিষ্য তহবিল
        [$ownShare, $employerShare] = ['0', '0'];
        foreach ($this->fund->ledgerOf($employee) as $row) {
            $ownShare = bcadd($ownShare, $row['employee_share'], 4);
            $employerShare = bcadd($employerShare, $row['employer_share'], 4);
        }
        $years = $employee->joining_date === null ? 0 : (int) $employee->joining_date->diffInYears($lastDay);
        $vested = bccomp($employerShare, '0', 4) > 0 ? $this->fund->vestedEmployerShare($employerShare, $years) : '0.00';

        if ($vested === null) {
            throw ValidationException::withMessages(['employee' => __('hr::settlement.pf_vesting_not_set')]);
        }

        $pf = Money::round(bcadd($ownShare, $vested, 4), 2);

        $earnings = bcadd(bcadd($wages, $leave['amount'], 2), bcadd($gratuity['obligation'], $pf, 2), 2);

        // ── অগ্রিম — পাওনার বেশি কাটা যায় না
        $advance = $this->advanceOutstanding($employee, $lastDay);
        $recovered = bccomp($advance, $earnings, 2) > 0 ? $earnings : $advance;

        return [
            'last_day' => $lastDay->toDateString(),
            'wages' => $wages,
            'leave_encashment' => $leave['amount'],
            'gratuity' => $gratuity['obligation'],
            'provident_fund' => $pf,
            'advance_recovered' => $recovered,
            'earnings' => $earnings,
            'net' => bcsub($earnings, $recovered, 2),
            'details' => [
                'unpaid_from' => $from->toDateString(),
                'wage_days' => $days,
                'monthly_gross' => Money::round($gross, 2),
                'leave_days' => $leave['days'],
                'leave_daily_rate' => $leave['daily_rate'],
                'gratuity_years' => $gratuity['years'],
                'gratuity_days' => $gratuity['days'],
                'gratuity_basic' => Money::round((string) $gratuity['basic'], 2),
                'pf_own' => Money::round($ownShare, 2),
                'pf_employer' => Money::round($employerShare, 2),
                'pf_vested' => $vested,
                'pf_forfeited' => Money::round(bcsub($employerShare, $vested, 4), 2),
                'service_years' => $years,
                'advance_outstanding' => $advance,
            ],
        ];
    }

    /**
     * ⭐ কাগজ বানানো আর মালিকের অনুমোদনে পাঠানো — খাতায় কিছু বসে না।
     *
     * @throws ValidationException শেষ দিন নেই, আগের কাগজ খোলা বা অনুমোদিত, পাওনা শূন্য, বা অনুমোদনের কেউ নেই
     */
    public function prepare(Employee $employee, string $separation, ?string $note, User $by): FinalSettlement
    {
        if (! in_array($separation, FinalSettlement::SEPARATIONS, true)) {
            throw ValidationException::withMessages(['separation' => __('hr::settlement.bad_separation')]);
        }

        return DB::transaction(function () use ($employee, $separation, $note, $by) {
            // ⛔ তালা — একই কর্মীর দুইটা কাগজ একসাথে নয়
            $locked = Employee::query()->withTrashed()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $open = FinalSettlement::query()->where('employee_id', $locked->id)
                ->whereIn('status', [FinalSettlement::PENDING, FinalSettlement::APPROVED])->exists();

            if ($open) {
                throw ValidationException::withMessages(['employee' => __('hr::settlement.exists')]);
            }

            $figures = $this->compute($locked);

            if (bccomp($figures['earnings'], '0', 2) <= 0) {
                throw ValidationException::withMessages(['employee' => __('hr::settlement.nothing_due')]);
            }

            $head = Company::query()->find(CompanyContext::id())?->defaultBranch()?->id;

            $settlement = FinalSettlement::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $locked->branch_id ?? $head,
                'employee_id' => $locked->id,
                'last_day' => $figures['last_day'],
                'separation' => $separation,
                'note' => $note === null ? null : mb_substr(trim($note), 0, 500),
                'wages' => $figures['wages'],
                'leave_encashment' => $figures['leave_encashment'],
                'gratuity' => $figures['gratuity'],
                'provident_fund' => $figures['provident_fund'],
                'advance_recovered' => $figures['advance_recovered'],
                'net' => $figures['net'],
                'details' => $figures['details'],
                'status' => FinalSettlement::PENDING,
                'requested_by' => $by->id,
            ]);

            $approval = $this->engine->request(
                $settlement,
                self::MODULE,
                self::ACTION,
                amount: $figures['net'],
                // ⓘ কারণে কেবল নাম আর শেষ দিন — অঙ্ক নয়; অনুরোধের কারণ নিরীক্ষকও পড়েন
                reason: __('hr::settlement.asked', ['employee' => $locked->name(), 'date' => $figures['last_day']]),
                userId: (int) $by->id,
                matchOn: [],
            );

            // ⛔ fail-closed — কোনো নিয়মই না ধরলে (মডিউলের ঘোষণা সরালে) কাগজ হয় না, সরাসরি খাতায় কখনো নয়
            if ($approval === null) {
                throw ValidationException::withMessages(['employee' => __('hr::settlement.no_approver')]);
            }

            return $settlement;
        });
    }

    public function approvalOf(FinalSettlement $settlement): ?Approval
    {
        return $this->engine->latestFor($settlement, self::ACTION);
    }

    /** ⓘ পাতার বোতাম — এই মানুষ এখন অনুমোদন দিতে পারেন কি না, ইনবক্সের একই প্রশ্নে */
    public function canDecide(FinalSettlement $settlement, ?User $user): bool
    {
        $approval = $this->approvalOf($settlement);

        return $user !== null && $settlement->isPending() && $approval !== null && $approval->isPending()
            && $this->engine->canDecide($approval, $user);
    }

    public function approve(FinalSettlement $settlement, User $by): FinalSettlement
    {
        $approval = $this->pendingApproval($settlement, $by);
        $this->engine->approve($approval, $by);

        return $settlement->fresh();
    }

    public function reject(FinalSettlement $settlement, User $by, string $reason): FinalSettlement
    {
        $approval = $this->pendingApproval($settlement, $by);
        $this->engine->reject($approval, $by, $reason);

        return $settlement->fresh();
    }

    /**
     * ⭐ শেষ সিদ্ধান্ত পড়ল ([[FinishTheFinalSettlementOnTheLastSignature]]) — অনুমোদিত হলে খাতায়, "না" হলে কাগজ বন্ধ।
     * ⓘ একটা অনুমোদন একবারই খাটে ([[Approval::markUsed()]])।
     */
    public function finishSigned(Approval $approval): void
    {
        if ($approval->action !== self::ACTION || $approval->isUsed()) {
            return;
        }

        $settlement = FinalSettlement::query()->find($approval->approvable_id);

        if ($settlement === null || ! $settlement->isPending()) {
            return;
        }

        if ($approval->status === Approval::REJECTED) {
            $settlement->forceFill(['status' => FinalSettlement::REJECTED, 'decided_at' => now(), 'decided_by' => $this->lastDecider($approval)])->save();

            return;
        }

        if ($approval->status !== Approval::APPROVED) {
            return;
        }

        DB::transaction(function () use ($settlement, $approval): void {
            $this->post($settlement);
            $settlement->forceFill(['status' => FinalSettlement::APPROVED, 'decided_at' => now(), 'decided_by' => $this->lastDecider($approval)])->save();
            $approval->markUsed();
        });
    }

    /** ⭐ অনুমোদনের কেন্দ্রের "ফিরিয়ে নিন" — কাগজও বন্ধ */
    public function closeWithdrawn(Approval $approval): void
    {
        if ($approval->action !== self::ACTION) {
            return;
        }

        FinalSettlement::query()->whereKey($approval->approvable_id)->where('status', FinalSettlement::PENDING)
            ->update(['status' => FinalSettlement::WITHDRAWN, 'updated_at' => now()]);
    }

    /** ⓘ অনুমোদিত কাগজগুলোয় মেটানো গ্র্যাচুইটি — শাখা ধরে ([[Gratuity::accrue()]] বাদ দেয়) */
    public static function gratuitySettled(?int $branchId): string
    {
        return bcadd((string) FinalSettlement::query()->where('status', FinalSettlement::APPROVED)
            ->when($branchId === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $branchId))
            ->sum('gratuity'), '0', 4);
    }

    private function post(FinalSettlement $settlement): void
    {
        $employee = $settlement->employee;
        $narration = __('hr::settlement.narration', ['employee' => $employee?->name() ?? '#'.$settlement->employee_id, 'date' => $settlement->last_day->toDateString()]);
        $branch = $settlement->branch_id === null ? null : (int) $settlement->branch_id;
        $party = ['party_type' => Employee::drillSourceType(), 'party_id' => (int) $settlement->employee_id];
        $lines = [];

        $pay = bcadd((string) $settlement->wages, (string) $settlement->leave_encashment, 4);
        foreach ([
            [StandardChart::SALARY_EXPENSE, $pay, 'debit', []],
            [StandardChart::GRATUITY_LIABILITY, (string) $settlement->gratuity, 'debit', []],
            [StandardChart::PROVIDENT_FUND_PAYABLE, (string) $settlement->provident_fund, 'debit', $party],
            [StandardChart::EMPLOYEE_ADVANCE, (string) $settlement->advance_recovered, 'credit', $party],
            [StandardChart::SALARY_PAYABLE, (string) $settlement->net, 'credit', $party],
        ] as [$code, $amount, $side, $extra]) {
            if (bccomp($amount, '0', 2) <= 0) {
                continue;
            }

            $lines[] = ['account_id' => (int) $this->account($code)->id, $side => $amount, 'narration' => $narration, 'branch_id' => $branch] + $extra;
        }

        $this->posting->post(
            sourceType: self::SOURCE_TYPE,
            sourceId: (int) $settlement->id,
            trxDate: $settlement->last_day,
            lines: $lines,
            documentNo: 'FS-'.$settlement->id,
            branchId: $branch,
        );
    }

    /** ⓘ শেষ নিশ্চিত বেতনের রানের মাসের পরের দিন — রান না থাকলে যোগদানের দিন */
    private function unpaidFrom(Employee $employee): Carbon
    {
        $month = DB::table('hr_payslips as s')
            ->join('hr_payroll_runs as r', 'r.id', '=', 's.payroll_run_id')
            ->where('s.employee_id', $employee->id)
            ->where('r.run_type', PayrollRun::SALARY)
            ->where('r.status', DocumentStatus::CONFIRMED)
            ->whereNull('r.deleted_at')
            ->whereNull('s.deleted_at')
            ->max('r.month');

        if ($month !== null) {
            return Carbon::parse((string) $month)->endOfMonth()->addDay()->startOfDay();
        }

        return ($employee->joining_date ?? $employee->leaving_date)->copy()->startOfDay();
    }

    /** ⓘ কর্মীর অগ্রিমের খাতে শেষ দিন পর্যন্ত বাকি (Dr − Cr), ঋণাত্মক হলে শূন্য */
    private function advanceOutstanding(Employee $employee, Carbon $on): string
    {
        $account = StandardChart::find(StandardChart::EMPLOYEE_ADVANCE);

        if ($account === null) {
            return '0.00';
        }

        $net = (string) DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->where('account_id', $account->id)
            ->where('party_type', Employee::drillSourceType())
            ->where('party_id', $employee->id)
            ->where('trx_date', '<=', $on->toDateString())
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')
            ->value('n');

        return bccomp($net, '0', 4) > 0 ? Money::round($net, 2) : '0.00';
    }

    private function account(string $code): Account
    {
        $account = StandardChart::find($code);

        if ($account === null) {
            throw ValidationException::withMessages(['account' => __('hr::settlement.account_missing', ['code' => $code])]);
        }

        return $account;
    }

    private function pendingApproval(FinalSettlement $settlement, User $by): Approval
    {
        $approval = $this->approvalOf($settlement);

        if (! $settlement->isPending() || $approval === null || ! $approval->isPending()) {
            throw ValidationException::withMessages(['settlement' => __('hr::settlement.not_pending')]);
        }

        if (! $this->engine->canDecide($approval, $by)) {
            throw ValidationException::withMessages(['settlement' => (int) $settlement->requested_by === (int) $by->id
                ? __('hr::settlement.another_hand')
                : __('hr::settlement.owner_only')]);
        }

        return $approval;
    }

    private function lastDecider(Approval $approval): ?int
    {
        $id = $approval->decisions()->latest('id')->value('user_id');

        return $id === null ? null : (int) $id;
    }
}
