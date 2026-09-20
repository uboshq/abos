<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanInstalment;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Inventory\Services\StockFacts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * ঝুঁকির ড্যাশবোর্ড — যেগুলো সত্যিই ডিপোকে কামড়ায়। মানচিত্র §১, ২০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⭐ নতুন কোনো টেবিল নেই, ইচ্ছাকৃত ─────────────────────────────────
 * প্রতিটা ঝুঁকি আগে থেকেই কোথাও না কোথাও গোনা হয় — নগদের পূর্বাভাস,
 * ব্যাংক সুবিধার স্ট্যান্ডিং, জমার মেয়াদ, ঋণের কিস্তি, বসে থাকা মাল।
 * ⚠️ আলাদা করে আবার গুনলে একদিন দুই পর্দা দুই সংখ্যা বলত, আর তখন কোনটা
 * সত্যি তা কেউ বলতে পারত না। ⓘ তাই এই পাতা কেবল **এক জায়গায় আনে**।
 *
 * ── ⓘ "ঝুঁকি" বলতে এখানে কী ────────────────────────────────────────────
 * তিনটা স্তর: `bad` (আজই দেখা দরকার), `warn` (সামনে আসছে), আর যেটা
 * ঠিক আছে সেটা তালিকাতেই আসে না। ⛔ সব ঠিক থাকলে পাতা ফাঁকা — আর
 * সেটাই ঠিক: সবুজ সারির লম্বা তালিকা মানুষকে লাল সারিও এড়াতে শেখায়।
 *
 * ⚠️ নগদের "মেঝে" (কত টাকার নিচে নামলে বিপদ) কোথাও বসানো নেই, আর এখানে
 * বানানোও হয়নি। ⓘ তার বদলে পূর্বাভাসের নিজের উত্তরটাই ব্যবহার হয়: কোনো
 * ঝুড়ির শেষে হাতের টাকা **শূন্যের নিচে** নামলে সেটাই নগদের ঝুঁকি —
 * মালিকের বসানো কোনো সংখ্যার দরকার পড়ে না।
 */
final class RiskBoard
{
    /**
     * যে ঝুঁকিগুলো এই পাতা তুলতে পারে।
     *
     * ⓘ তালিকাটা কেবল পরীক্ষার জন্য নয় — প্রতিটা চাবির দুইটা করে কথা
     * লাগে (`<চাবি>` আর `<চাবি>_hint`), দুই ভাষায়। ⚠️ নতুন একটা ঝুঁকি
     * যোগ করে ভাষার ফাইল ভুলে গেলে পাতায় কাঁচা চাবিটাই ছাপা হত, আর
     * সেটা দেখতে বাগ নয় — দেখতে **ইংরেজি**।
     *
     * @var list<string>
     */
    public const KEYS = [
        'cash_runs_out',
        'receivables_overdue',
        'payables_due',
        'facility_used',
        'deposits_maturing',
        'loan_instalments',
        'stock_stuck',
    ];

    /** সুবিধার সীমার কতটা ব্যবহার হলে সতর্ক, আর কতটায় বিপদ। */
    private const FACILITY_WARN = '75';

    private const FACILITY_BAD = '90';

    /** কত দিনের ভিতরের মেয়াদ ও কিস্তি দেখানো হয়। */
    private const HORIZON_DAYS = 30;

    /** কত দিন বসে থাকলে মালকে "আটকে যাওয়া টাকা" ধরা হয়। */
    private const STUCK_DAYS = 90;

    public function __construct(
        private readonly AccountsFacts $facts,
        private readonly CashForecast $forecast,
        private readonly BankFacilityService $facilities,
        private readonly StockFacts $stock,
    ) {}

    /**
     * @return list<array{key: string, level: string, value: string, hint: array<string, string>, href: ?string}>
     */
    public function risks(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();

        /*
         * ⚠️ পূর্বাভাসটা একবারই — তিনটা ঝুঁকি ওটা থেকেই আসে (নগদ, পাওনা,
         * দেনা)। ⓘ প্রতিটার জন্য আলাদা করে ডাকলে একই ভারী হিসাব তিনবার
         * চলত: প্রতিটা পোস্ট করা বিল ও কিস্তি তিনবার পড়া হত।
         */
        $forecast = $this->forecast->build($today);

        return array_values(array_filter([
            ...$this->cash($forecast),
            $this->receivables($forecast),
            $this->payables($forecast),
            ...$this->facilityLimits(),
            $this->maturingDeposits($today),
            $this->instalments($today),
            $this->stuckStock(),
        ]));
    }

    /**
     * নগদ — পূর্বাভাসের কোনো ঝুড়ির শেষে টাকা শূন্যের নিচে নামে কি না।
     *
     * @param  array{opening: string, rows: list<array<string, string>>}  $forecast
     * @return list<array<string, mixed>>
     */
    private function cash(array $forecast): array
    {
        foreach ($forecast['rows'] as $row) {
            if (bccomp($row['closing'], '0', 4) < 0) {
                return [[
                    'key' => 'cash_runs_out',
                    'level' => 'bad',
                    'value' => $row['closing'],
                    // ⓘ কেবল যা বার্তায় বসে — না-বসা ঘর রাখলে একদিন কেউ
                    // ভাবতেন সেটা দেখানো হচ্ছে, অথচ হত না
                    'hint' => ['until' => $row['until']],
                    'href' => $this->link('finance.forecast.cash'),
                ]];
            }
        }

        return [];
    }

    /** ⓘ পূর্বাভাসের প্রথম ঝুড়ি — মেয়াদ পেরোনো আর মেয়াদ-না-লেখা বাকি। */
    private function receivables(array $forecast): ?array
    {
        $due = $forecast['rows'][0]['receivables'] ?? '0';

        if (bccomp($due, '0', 4) <= 0) {
            return null;
        }

        return [
            'key' => 'receivables_overdue',
            'level' => 'bad',
            'value' => $due,
            'hint' => ['total' => $this->facts->receivable()],
            // ⓘ বয়সের ভাগটা যেখানে একবার লেখা আছে, দরজাটা সেখানেই যায়
            'href' => $this->link('customer.report.show', ['slug' => 'ageing'])
                ?? $this->link('sales.collection.index'),
        ];
    }

    private function payables(array $forecast): ?array
    {
        $due = $forecast['rows'][0]['payables'] ?? '0';

        if (bccomp($due, '0', 4) <= 0) {
            return null;
        }

        return [
            'key' => 'payables_due',
            'level' => 'warn',
            'value' => $due,
            'hint' => ['total' => $this->facts->payable()],
            'href' => $this->link('purchase.payment.index'),
        ];
    }

    /**
     * ব্যাংক সুবিধা — সীমার কতটা ব্যবহার হয়ে গেছে।
     *
     * ⚠️ CC-তে তুলনাটা ড্রয়িং পাওয়ারের সাথে, সীমার সাথে নয়: স্টক কমলে
     * ব্যাংক যতটা দেবে সেটাও কমে, আর সীমা তখন কেবল কাগজের সংখ্যা।
     *
     * @return list<array<string, mixed>>
     */
    private function facilityLimits(): array
    {
        $facilities = BankFacility::query()->live()->get();

        if ($facilities->isEmpty()) {
            return [];
        }

        $standing = $this->facilities->standing($facilities);
        $out = [];

        foreach ($facilities as $facility) {
            $used = $standing[$facility->id]['used'] ?? '0';
            $ceiling = $facility->drawingPower() ?? (string) $facility->limit_amount;

            if (bccomp($ceiling, '0', 4) <= 0) {
                continue;
            }

            $usedPct = bcmul(bcdiv($used, $ceiling, 6), '100', 2);
            $level = match (true) {
                bccomp($usedPct, self::FACILITY_BAD, 2) >= 0 => 'bad',
                bccomp($usedPct, self::FACILITY_WARN, 2) >= 0 => 'warn',
                default => null,
            };

            if ($level === null) {
                continue;
            }

            $out[] = [
                'key' => 'facility_used',
                'level' => $level,
                'value' => $usedPct,
                'hint' => [
                    // ⓘ সুবিধার নিজের কোনো "নাম" নেই — ব্যাংক আর কাগজের নম্বরই পরিচয়
                    'name' => trim(($facility->bank ?? '').' '.($facility->document_no ?? '')),
                    'used' => $used,
                    'ceiling' => $ceiling,
                ],
                // ⓘ সুবিধাটার নিজের পাতা, তালিকা নয় — সাতটা সুবিধার
                // মধ্যে কোনটা ভরে আসছে, সেটা খুঁজতে হয় না
                'href' => $this->link('finance.bank_facility.show', [$facility])
                    ?? $this->link('finance.bank_facility.index'),
            ];
        }

        return $out;
    }

    /** মেয়াদ ঘনিয়ে আসা জমা — টাকাটা ফেরত নিতে বা নবায়ন করতে হয়। */
    private function maturingDeposits(Carbon $today): ?array
    {
        $rows = Deposit::query()->open()
            ->with('kind')
            ->whereNotNull('matures_on')
            ->whereBetween('matures_on', [$today->toDateString(), $today->copy()->addDays(self::HORIZON_DAYS)->toDateString()])
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        /*
         * ⭐ দরজাটা মেয়াদের ট্যাবে — সাধারণ তালিকায় নয়।
         *
         * ⓘ জমার পর্দা ইস্যুকারী ধরে ভাগ করা (ব্যাংক · প্রতিষ্ঠান · ব্যক্তি),
         * তাই ঠিকানায় একটা ইস্যুকারী লাগেই। ⚠️ সবগুলো এক ইস্যুকারীর হলে
         * সেখানেই পাঠানো হয়; মিশ্র হলে সবার তালিকায় — নইলে দরজাটা
         * অর্ধেক সারি লুকিয়ে রাখত।
         */
        $issuers = $rows->map(fn (Deposit $d) => $d->kind?->issuer)->filter()->unique();

        $href = $issuers->count() === 1
            ? $this->link('finance.deposit.index', ['issuer' => $issuers->first(), 'tab' => 'maturing'])
            : $this->link('finance.deposit.all');

        return [
            'key' => 'deposits_maturing',
            'level' => 'warn',
            'value' => (string) $rows->count(),
            'hint' => [
                // ⓘ জমার টাকার ঘরটার নাম `principal` — `amount` নামে কিছু নেই
                'amount' => $rows->reduce(fn (string $sum, Deposit $d) => bcadd($sum, (string) $d->principal, 4), '0'),
                'days' => (string) self::HORIZON_DAYS,
            ],
            'href' => $href,
        ];
    }

    /** সামনের ৩০ দিনে যে ঋণের কিস্তি দিতে হবে। */
    private function instalments(Carbon $today): ?array
    {
        $rows = LoanInstalment::query()
            ->with('loan')
            ->where('status', LoanInstalment::DUE)
            ->whereHas('loan', fn ($q) => $q->whereIn('status', DocumentStatus::POSTED)
                ->where('direction', Loan::TAKEN))
            ->whereBetween('due_date', [$today->toDateString(), $today->copy()->addDays(self::HORIZON_DAYS)->toDateString()])
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $amount = $rows->reduce(
            fn (string $sum, LoanInstalment $i) => bcadd($sum, bcsub($i->total(), (string) ($i->paid_amount ?? 0), 4), 4),
            '0',
        );

        /*
         * ⚠️ মেয়াদ পেরোনো কিস্তি থাকলে সেটা আর "সামনে আসছে" নয় —
         * ⓘ ব্যাংকের কিস্তি একদিন দেরি হলেও সুদ আর সম্পর্ক দুইটাই নড়ে।
         */
        $overdue = $rows->contains(fn (LoanInstalment $i) => $i->isOverdue());

        return [
            'key' => 'loan_instalments',
            'level' => $overdue ? 'bad' : 'warn',
            'value' => $amount,
            'hint' => ['count' => (string) $rows->count(), 'days' => (string) self::HORIZON_DAYS],

            // ⓘ একটাই ঋণের কিস্তি হলে সোজা ওই ঋণে, নইলে তালিকায়
            'href' => $rows->pluck('loan_id')->unique()->count() === 1
                ? ($this->link('accounts.loan.show', [$rows->first()->loan]) ?? $this->link('accounts.loan.index'))
                : $this->link('accounts.loan.index'),
        ];
    }

    /** ৯০ দিন ধরে নড়েনি এমন মালে আটকে থাকা টাকা। */
    private function stuckStock(): ?array
    {
        $value = $this->stock->agingValue(self::STUCK_DAYS);

        if (bccomp($value, '0', 4) <= 0) {
            return null;
        }

        return [
            'key' => 'stock_stuck',
            'level' => 'warn',
            'value' => $value,
            'hint' => [
                'count' => (string) $this->stock->stagnantCount(self::STUCK_DAYS),
                'days' => (string) self::STUCK_DAYS,
            ],
            'href' => $this->link('inventory.stock.movement', ['type' => 'non', 'days' => 90]),
        ];
    }

    /**
     * দরজাটা থাকলে তবেই লিংক।
     *
     * ⚠️ রুটের নাম বদলালে বা মডিউল বন্ধ থাকলে `route()` ছুড়ে ফেলত, আর
     * তখন গোটা ঝুঁকির পাতা ৫০০ দিত — একটা লিংকের জন্য।
     *
     * @param  array<string, mixed>  $params
     */
    private function link(string $name, array $params = []): ?string
    {
        return Route::has($name) ? route($name, $params) : null;
    }
}
