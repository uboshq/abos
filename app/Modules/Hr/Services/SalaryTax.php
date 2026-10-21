<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Services\SettingsService;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use Illuminate\Support\Carbon;

/**
 * ⭐ বেতন থেকে উৎসে কর (TDS) — আয়কর আইন ২০২৩ ধারা ৮৬: নিয়োগকর্তা প্রতি মাসে আনুমানিক বার্ষিক করের বারো ভাগের এক ভাগ কাটেন (মালিকের
 * অনুমোদিত আইনি বেতনের পরিকল্পনা, a6, ২১ অক্টোবর ২০২৬)।
 *
 * ── ⭐ সূত্র ([[estimate()]]) ─────────────────────────────────────────────
 *   বার্ষিক বেতন  = মাসের কাঠামোর মোট আয় (প্রতিটা আয়ের খাত, অনুপস্থিতি আর অধিকাল ছাড়া) × ১২
 *   ছাড়          = min(বার্ষিক বেতন ÷ ৩, ছাড়ের সীমা) — আইনের ধারা ৩২-এর "বেতনের এক-তৃতীয়াংশ বা ৪,৫০,০০০, যেটা কম"
 *                   (সীমা সেটিংয়ে, ডিফল্ট 450000)
 *   করযোগ্য আয়   = বার্ষিক বেতন − ছাড়, শূন্যের নিচে নয়
 *   বার্ষিক কর     = ধাপে ধাপে ([[annualTax()]]) — প্রতিটা ধাপের চওড়া অংশে তার হার
 *   মাসিক কর্তন   = বার্ষিক কর ÷ ১২, পয়সায় একবার গোল
 * ⚠️ বিনিয়োগের রেয়াত, ন্যূনতম কর আর বছরের শেষে সমন্বয় এখানে নেই — রিটার্নে হিসাব হয়; এই কর্তন আইনের "আনুমানিক" ভাগ।
 *
 * ── ⭐ ধাপ ─────────────────────────────────────────────────────────────────
 * সেটিং `hr.tax.slabs` — কমা দিয়ে আলাদা "চওড়া:হার", শেষেরটা "*:হার" (বাকি সব) হতে পারে; যেমন
 * `350000:0, 100000:5, 400000:10, 500000:15, 500000:20, 2000000:25, *:30`। শেষে "*" না থাকলে শেষের হার বাকি অংশেও।
 * ⛔ ডিফল্টে খালি — খালি (বা পড়া না গেলে) কিছুই কাটা হয় না, আর রানের পাতায় "করের ধাপ বসানো নেই" ([[note()]])। হার প্রতি বাজেটে
 * বদলায়; কোডে বসানো হার এক বছর পরে নীরবে ভুল কাটত।
 *
 * ⓘ খাতায়: কর্তনের সারি `TDS`, ২১৩২ "প্রদেয় উৎসে কর (বেতন)" ক্রেডিট — বেতনের রানের একই দাখিলায় ([[PayrollService::ledgerLines()]])।
 */
final class SalaryTax
{
    public const SLABS = 'hr.tax.slabs';

    public const EXEMPTION_CAP = 'hr.tax.exemption_cap';

    /** ⓘ বেতনের স্লিপের সারির কোড */
    public const LINE_CODE = 'TDS';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly SalaryStructureService $salaries,
    ) {}

    /**
     * @return list<array{width: ?string, rate: string}>|null ধাপগুলো; খালি সেটিংয়ে `[]`, পড়া না গেলে `null`
     */
    public function slabs(): ?array
    {
        $raw = trim((string) $this->settings->get(self::SLABS));

        if ($raw === '') {
            return [];
        }

        $slabs = [];
        $parts = preg_split('/[,\n;]+/', $raw) ?: [];

        foreach ($parts as $i => $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (! preg_match('/^(\*|\d{1,12}(?:\.\d{1,2})?)\s*:\s*(\d{1,3}(?:\.\d{1,4})?)$/', $part, $m) || bccomp($m[2], '100', 4) > 0) {
                return null;
            }

            // ⓘ "*" কেবল শেষে — মাঝে থাকলে পরের ধাপগুলোর মানে থাকে না
            if ($m[1] === '*' && $i !== array_key_last($parts)) {
                return null;
            }

            $slabs[] = ['width' => $m[1] === '*' ? null : bcadd($m[1], '0', 2), 'rate' => bcadd($m[2], '0', 4)];
        }

        return $slabs;
    }

    public function hasSlabs(): bool
    {
        return ($this->slabs() ?? []) !== [];
    }

    /** ⓘ রানের পাতার বাংলা নোট — ধাপ বসানো থাকলে `null` */
    public function note(): ?string
    {
        $slabs = $this->slabs();

        return match (true) {
            $slabs === null => __('hr::message.tax_slabs_unreadable'),
            $slabs === [] => __('hr::message.tax_slabs_missing'),
            default => null,
        };
    }

    /** ছাড়ের সীমা — সাধারণ সংখ্যা; ভুল লেখায় 450000 */
    public function exemptionCap(): string
    {
        $value = trim((string) $this->settings->get(self::EXEMPTION_CAP));

        return preg_match('/^\d{1,12}(\.\d{1,2})?$/', $value) ? bcadd($value, '0', 2) : '450000.00';
    }

    /** বার্ষিক কর — ধাপে ধাপে, চার ঘরে (গোল কেবল মাসিক ভাগে) */
    public function annualTax(string $taxable): string
    {
        $left = bccomp($taxable, '0', 4) > 0 ? $taxable : '0';
        $tax = '0';
        $slabs = $this->slabs() ?? [];

        foreach ($slabs as $i => $slab) {
            if (bccomp($left, '0', 4) <= 0) {
                break;
            }

            $open = $slab['width'] === null || $i === array_key_last($slabs);
            $portion = $open || bccomp($left, $slab['width'], 4) <= 0 ? $left : $slab['width'];

            $tax = bcadd($tax, bcdiv(bcmul($portion, $slab['rate'], 10), '100', 10), 10);
            $left = bcsub($left, $portion, 4);
        }

        return bcadd($tax, '0', 4);
    }

    /**
     * ⭐ একজনের আনুমানিক কর — মাস-শেষের কাঠামো থেকে (সূত্র উপরে)।
     *
     * @param  list<array{head: SalaryHead, amount: string}>|null  $components  বেতনের রান হাতের কাঠামো দেয়, যাতে দ্বিতীয়বার না পড়ে
     * @return array{annual: string, exempt: string, taxable: string, tax: string, monthly: string}
     */
    public function estimate(Employee $employee, Carbon $monthEnd, ?array $components = null): array
    {
        $monthlyGross = '0';

        foreach ($components ?? $this->salaries->componentsOn($employee, $monthEnd) as $component) {
            if ($component['head']->isEarning()) {
                $monthlyGross = bcadd($monthlyGross, (string) $component['amount'], 4);
            }
        }

        $annual = bcmul($monthlyGross, '12', 4);
        $third = bcdiv($annual, '3', 4);
        $exempt = bccomp($third, $this->exemptionCap(), 4) < 0 ? $third : $this->exemptionCap();
        $taxable = bcsub($annual, $exempt, 4);
        $taxable = bccomp($taxable, '0', 4) > 0 ? $taxable : '0';
        $tax = $this->annualTax($taxable);

        return [
            'annual' => $annual,
            'exempt' => bcadd($exempt, '0', 4),
            'taxable' => $taxable,
            'tax' => $tax,
            'monthly' => $this->hasSlabs() ? Money::round(bcdiv($tax, '12', 10), 2) : '0.00',
        ];
    }

    public function payableAccount(): ?Account
    {
        return Account::query()->postable()->where('code', StandardChart::TDS_PAYABLE)->first();
    }

    /** ⓘ আয়ের বছর — জুলাই থেকে জুন; `$start` = যে বছরের জুলাইয়ে শুরু */
    public static function incomeYearOf(Carbon $date): int
    {
        return $date->month >= 7 ? $date->year : $date->year - 1;
    }

    /**
     * ⭐ বছরের কর-সনদের সারি — আয়ের বছরের প্রতিটা নিশ্চিত রানের বেতনের স্লিপ: মাস, রান, মোট বেতন, উৎসে কাটা কর।
     *
     * ⓘ বেতনের স্লিপ থেকে, খাতা থেকে নয় — সনদ কর্মীর হাতে যাওয়া স্লিপের যোগ; খাতা একই অঙ্ক বলে ([[PayrollService::ledgerLines()]])।
     *
     * @return array{rows: list<array{month: Carbon, run_no: string, gross: string, tds: string}>, gross: string, tds: string}
     */
    public function certificate(Employee $employee, int $start): array
    {
        $from = Carbon::create($start, 7, 1)->startOfDay();
        $to = $from->copy()->addYear()->subDay();

        $slips = Payslip::query()
            ->where('employee_id', $employee->id)
            ->whereHas('run', fn ($run) => $run->withoutGlobalScope('user-branch')
                ->where('status', DocumentStatus::CONFIRMED)
                ->whereDate('month', '>=', $from->toDateString())
                ->whereDate('month', '<=', $to->toDateString()))
            ->with(['run' => fn ($run) => $run->withoutGlobalScope('user-branch'), 'lines'])
            ->get()
            ->sortBy(fn (Payslip $slip) => [$slip->run->month->toDateString(), $slip->run->id])
            ->values();

        $rows = [];
        $gross = '0';
        $tds = '0';

        foreach ($slips as $slip) {
            $cut = $slip->lines
                ->filter(fn (PayslipLine $line) => $line->kind === SalaryHead::DEDUCTION && $line->head_code === self::LINE_CODE)
                ->reduce(fn ($carry, PayslipLine $line) => bcadd($carry, (string) $line->amount, 4), '0');

            $rows[] = [
                'month' => $slip->run->month->copy(),
                'run_no' => (string) $slip->run->document_no,
                'gross' => bcadd((string) $slip->gross, '0', 4),
                'tds' => $cut,
            ];
            $gross = bcadd($gross, (string) $slip->gross, 4);
            $tds = bcadd($tds, $cut, 4);
        }

        return ['rows' => $rows, 'gross' => $gross, 'tds' => $tds];
    }
}
