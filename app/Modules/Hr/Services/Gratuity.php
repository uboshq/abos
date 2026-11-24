<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\GratuityProvision;
use App\Modules\Hr\Support\BranchReach;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ গ্র্যাচুইটি — চাকরির সাথে জমে ওঠা পাওনা, প্রতি মাসে খাতায় (BLA ২০০৬ ধারা ২(১০), IAS 19 ¶৬৭–৭০; fe, ২১ অক্টোবর ২০২৬, দফা ৮)।
 *
 * ── ⭐ সূত্র ([[obligation()]]) ───────────────────────────────────────────
 *   গোনা বছর  = যোগদান থেকে হিসাবের দিন পর্যন্ত পূর্ণ বছর, আর বাকি অংশ ছয় মাসের বেশি হলে আরও এক (ধারা ২(১০): "প্রত্যেক পূর্ণ বৎসর
 *               অথবা ছয় মাসের অধিক সময়")
 *   দিন        = গোনা বছর × ৩০; চাকরি দশ বছরের বেশি (গোনা বছর > ১০) হলে প্রতিটা বছর × ৪৫
 *   দায়        = শেষ মূল (সেদিনের কাঠামো) × দিন ÷ ৩০, পয়সায় একবার গোল
 * ⓘ সেদিন যিনি চাকরিতে নেই, তাঁর দায় এখানে শূন্য — চাকরি ছাড়ার পাওনা চূড়ান্ত নিষ্পত্তির কাজ (আসছে)।
 *
 * ── ⭐ খাতায় — পুরো দায়ে মেলানো (true-up) ([[accrue()]]) ────────────────
 * প্রতি মাস-শেষে প্রতিটা শাখার কর্মীদের মোট দায় গোনা হয়; এ পর্যন্ত বসা সব সঞ্চিতির যোগ বাদ দিলে যা থাকে, সেটাই এই মাসের দাখিলা —
 * ধনাত্মক হলে ৫২২৭ খরচ ডেবিট / ২১৩৪ দায় ক্রেডিট, ঋণাত্মক হলে (কেউ চলে গেলেন, মূল কমল) উল্টো। ফলে মাস-শেষে ২১৩৪-এর জের ঠিক
 * হিসাব করা দায়ের সমান — বেতন বাড়লে আগের বছরগুলোর বাড়তি দায়ও সেই মাসেই বসে (IAS 19-এর পুনঃপরিমাপ, সরল রূপে; আক্চুয়ারিয়াল
 * ছাড়/ভবিষ্যৎ বেতনবৃদ্ধি ধরা হয় না)। ⓘ শাখা = কর্মীর শাখা, শাখাহীন কর্মী কোম্পানির প্রধান শাখায় — বেতনের দাখিলার একই নিয়ম।
 * ⓘ এ পর্যন্ত বসা = এই টেবিলের সারির যোগ: চূড়ান্ত নিষ্পত্তি না আসা পর্যন্ত ২১৩৪ কেবল এই পথেই নড়ে।
 *
 * ⛔ মাসে একবার — সেই মাস-শাখার সারি থাকলে আবার বসে না। খাত না থাকলে থামে, বাংলায় কারণসহ।
 */
final class Gratuity
{
    public const ENABLED = 'hr.gratuity.enabled';

    /** পোস্টিং ইঞ্জিনে উৎসের নাম */
    public const SOURCE_TYPE = 'gratuity_provision';

    public function __construct(
        private readonly SalaryStructureService $salaries,
        private readonly SettingsService $settings,
        private readonly PostingEngine $posting,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::ENABLED);
    }

    /** গোনা বছর — পূর্ণ বছর, আর বাকি ছয় মাসের বেশি হলে এক বেশি */
    public static function countedYears(Carbon $joined, Carbon $asOf): int
    {
        $joined = $joined->copy()->startOfDay();
        $asOf = $asOf->copy()->startOfDay();

        if ($asOf->lt($joined)) {
            return 0;
        }

        $years = (int) $joined->diffInYears($asOf);
        $anniversary = $joined->copy()->addYearsNoOverflow($years);

        return $anniversary->copy()->addMonthsNoOverflow(6)->lt($asOf) ? $years + 1 : $years;
    }

    /** দিন — বছর × ৩০, দশ বছরের বেশি হলে বছর × ৪৫ */
    public static function daysFor(int $years): int
    {
        return $years * ($years > 10 ? 45 : 30);
    }

    /** দায় — মূল × দিন ÷ ৩০, পয়সায় গোল (খাঁটি হিসাব) */
    public static function amountFor(string $basic, int $days): string
    {
        return Money::round(bcdiv(bcmul($basic, (string) $days, 10), '30', 10), 2);
    }

    /**
     * একজনের দায় — সেদিন চাকরিতে না থাকলে শূন্য।
     *
     * @return array{years: int, days: int, basic: string, obligation: string}
     */
    public function obligation(Employee $employee, Carbon $asOf): array
    {
        if ($employee->joining_date === null || ! $employee->wasEmployedOn($asOf)) {
            return ['years' => 0, 'days' => 0, 'basic' => '0', 'obligation' => '0.00'];
        }

        $basic = '0';

        foreach ($this->salaries->componentsOn($employee, $asOf) as $component) {
            if ($component['head']->is_basic) {
                $basic = (string) $component['amount'];
            }
        }

        $years = self::countedYears($employee->joining_date, $asOf);
        $days = self::daysFor($years);

        return ['years' => $years, 'days' => $days, 'basic' => $basic, 'obligation' => self::amountFor($basic, $days)];
    }

    /**
     * ⭐ রিপোর্টের সারি — সেদিন চাকরিতে থাকা প্রতিজন (মানুষ ধরে চাইলে তাঁর দেখার শাখায়)।
     *
     * @return list<array{employee: Employee, years: int, days: int, basic: string, obligation: string}>
     */
    public function rows(Carbon $asOf, ?User $for = null): array
    {
        $staff = Employee::query()->onPayrollFor($asOf->copy()->endOfMonth())->orderBy('code');
        $staff = $for === null ? $staff : app(BranchReach::class)->employeesInView($staff, $for);
        $rows = [];

        foreach ($staff->get() as $employee) {
            if (! $employee->wasEmployedOn($asOf)) {
                continue;
            }

            $rows[] = ['employee' => $employee] + $this->obligation($employee, $asOf);
        }

        return $rows;
    }

    /**
     * ⭐ মাসের সঞ্চিতি — প্রতিটা শাখায় দায় পর্যন্ত মেলানো (উপরের নিয়ম)।
     *
     * @return list<GratuityProvision> এই মাসে বসা সারি (আগেই বসানো থাকলে খালি)
     */
    public function accrue(Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $expense = Account::query()->postable()->where('code', StandardChart::GRATUITY_EXPENSE)->first();
        $liability = Account::query()->postable()->where('code', StandardChart::GRATUITY_LIABILITY)->first();

        if ($expense === null || $liability === null) {
            throw ValidationException::withMessages(['account' => __('hr::validation.gratuity_accounts_missing')]);
        }

        return DB::transaction(function () use ($monthStart, $monthEnd, $expense, $liability) {
            // ⛔ তালা — দুইজন একসাথে চাপলে একই মাস দুইবার বসত
            Company::query()->whereKey(CompanyContext::id())->lockForUpdate()->first();

            // ⛔ খাতা কোম্পানির — কর্মী-খাতা এক হলেও ([[SharedAcrossCompaniesWhenAsked]]) সঞ্চিতি, কর্মী আর মেটানো অঙ্ক কেবল এই কোম্পানির
            if (GratuityProvision::query()->where('company_id', CompanyContext::id())->whereDate('month', $monthStart->toDateString())->exists()) {
                return [];
            }

            $head = Company::query()->find(CompanyContext::id())?->defaultBranch()?->id;
            $byBranch = [];

            foreach (Employee::query()->withTrashed()->where('company_id', CompanyContext::id())->whereNotNull('joining_date')->get() as $employee) {
                $branch = $employee->branch_id === null ? $head : (int) $employee->branch_id;
                $key = (string) $branch;
                $row = $employee->trashed() ? ['obligation' => '0.00'] : $this->obligation($employee, $monthEnd);
                $byBranch[$key] ??= ['branch' => $branch, 'obligation' => '0', 'count' => 0];
                $byBranch[$key]['obligation'] = bcadd($byBranch[$key]['obligation'], $row['obligation'], 4);
                $byBranch[$key]['count'] += bccomp($row['obligation'], '0', 2) > 0 ? 1 : 0;
            }

            // ⓘ আগে যে শাখায় সঞ্চিতি ছিল অথচ আজ কেউ নেই — সেটাও শূন্যে মেলাতে হয়
            foreach (GratuityProvision::query()->where('company_id', CompanyContext::id())->select('branch_id')->distinct()->pluck('branch_id') as $branch) {
                $byBranch[(string) $branch] ??= ['branch' => $branch === null ? null : (int) $branch, 'obligation' => '0', 'count' => 0];
            }

            $made = [];

            foreach ($byBranch as $group) {
                /*
                 * ⓘ এ পর্যন্ত বসা = সঞ্চিতির যোগ − চূড়ান্ত পাওনায় মেটানো গ্র্যাচুইটি ([[FinalSettlements]] Dr 2134)।
                 * ⛔ বাদ না দিলে চলে যাওয়া কর্মীর দায় পরের মাসে আবার "ছাড়া" হত — Dr 2134 / Cr 5227, অথচ 2134 আগেই নেমেছে।
                 */
                $booked = bcsub((string) GratuityProvision::query()->where('company_id', CompanyContext::id())
                    ->when($group['branch'] === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $group['branch']))
                    ->sum('amount'), FinalSettlements::gratuitySettled($group['branch']), 4);
                $amount = bcsub($group['obligation'], bcadd($booked, '0', 4), 4);

                if (bccomp($amount, '0', 2) === 0) {
                    continue;
                }

                $provision = GratuityProvision::query()->create([
                    'company_id' => CompanyContext::id(),
                    'branch_id' => $group['branch'],
                    'month' => $monthStart->toDateString(),
                    'obligation' => $group['obligation'],
                    'amount' => $amount,
                    'employee_count' => $group['count'],
                    'created_by' => auth()->id(),
                ]);

                $up = bccomp($amount, '0', 4) > 0;
                $abs = $up ? $amount : bcsub('0', $amount, 4);
                $narration = __('hr::message.gratuity_narration', ['month' => $monthStart->format('M Y')]);

                $this->posting->post(
                    sourceType: self::SOURCE_TYPE,
                    sourceId: (int) $provision->id,
                    trxDate: $monthEnd,
                    lines: [
                        ['account_id' => (int) ($up ? $expense->id : $liability->id), 'debit' => $abs, 'narration' => $narration, 'branch_id' => $group['branch']],
                        ['account_id' => (int) ($up ? $liability->id : $expense->id), 'credit' => $abs, 'narration' => $narration, 'branch_id' => $group['branch']],
                    ],
                    documentNo: 'GRT-'.$monthStart->format('Y-m'),
                    branchId: $group['branch'],
                );

                $made[] = $provision;
            }

            return $made;
        });
    }
}
