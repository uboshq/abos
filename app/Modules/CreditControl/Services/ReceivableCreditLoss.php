<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Services;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\LedgerEntry;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ গ্রাহকের পাওনার সম্ভাব্য ক্ষতি — IFRS 9 সরল পদ্ধতি, বয়সভিত্তিক হার, আর অনাদায়ী পাওনা বাদ দেওয়া (ERP ১০০%-এর হিসাবের
 * ফাঁক #১৮, ফ্রিজের পরের অংশ; fe, ২০ অক্টোবর ২০২৬)।
 *
 * <p>⭐ সংরক্ষণ (IFRS 9.5.5.15, B5.5.35): লক্ষ্য = গ্রাহকের বয়সের প্রতিটা ভাগ ([[PartyReports::ageing()]] — আদায় সবচেয়ে পুরনো
 * বকেয়া থেকে, অগ্রিম আলাদা) × সেই ভাগের হার (কোম্পানির সেটিং, `credit_control.ecl_rate_*`, শতাংশে; ডিফল্ট মালিকের অনুমোদিত
 * 1/3/10/50 — ২১ অক্টোবর ২০২৬, "plan onujayi koro")। ভাতার খাতে (1119) যা আছে তার সাথে তফাতটুকু একটা জাবেদায় — বাড়লে Dr পাওনার
 * ক্ষতির খরচ (5228) / Cr ভাতা, কমলে উল্টো — আর সেটা সোজা অনুমোদনে যায় ([[sendForApproval()]])।
 *
 * <p>⭐ ইতিহাসের হার — প্রতিটা হারের পাশে প্রস্তাব ([[history()]]): এক বছর আগের প্রতিটা ভাগের বকেয়ার কত অংশ পরের এক বছরে
 * অনাদায়ী বলে বাদ গেল। কেবল প্রস্তাব — হার বসায় না।
 *
 * <p>⭐ অনাদায়ী বাদ (IFRS 9.5.4.4): একজন গ্রাহকের বকেয়া থেকে, কারণসহ, একটা খসড়া জাবেদা — Cr পাওনা (সেই গ্রাহকের নামে),
 * Dr ভাতা যতটা আছে, বাকিটা Dr পাওনার ক্ষতির খরচ। বকেয়ার বেশি বাদ দেওয়া যায় না।
 *
 * <p>⛔ দুটোই কেবল খসড়া ভাউচার — পাকা হয় ভাউচারের নিজের পথে: লেখক ≠ পাকাকারী (সুইচ চালু থাকলে, [[VoucherService::writerMayNotPost()]]),
 * আর ভাউচারের সইয়ের ছক। তাই maker-checker আর মালিকের অনুমোদন-বন্ধের নিয়ম এখানে আলাদা করে লিখতে হয় না।
 * ⛔ কোম্পানির পুরো পাওনা ধরে — এক শাখায় সীমিত মানুষ এটা চালাতে পারেন না (অর্ধেক বকেয়া দেখে পুরো কোম্পানির ভাতা বসানো যেত)।
 */
final class ReceivableCreditLoss
{
    /** ⭐ মালিকের অনুমোদিত ডিফল্ট হার (২১ অক্টোবর ২০২৬) — কোম্পানি নিজের সেটিংয়ে বদলাতে পারে */
    public const DEFAULTS = [
        'bucket_current' => '1',
        'bucket_30' => '3',
        'bucket_60' => '10',
        'bucket_90' => '50',
    ];

    /** ইতিহাসের হার কত দিন পিছন থেকে মাপা — এক বছর আগের বকেয়া, পরের এক বছরের বাদ */
    public const HISTORY_MONTHS = 12;

    /** বয়সের ভাগ → হারের সেটিং */
    public const RATES = [
        'bucket_current' => 'credit_control.ecl_rate_current',
        'bucket_30' => 'credit_control.ecl_rate_30',
        'bucket_60' => 'credit_control.ecl_rate_60',
        'bucket_90' => 'credit_control.ecl_rate_90',
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly SettingsService $settings,
        private readonly VoucherService $vouchers,
    ) {}

    /**
     * তারিখে ভাতার হিসাব — প্রতিটা ভাগের বকেয়া, হার, সম্ভাব্য ক্ষতি; লক্ষ্য, ভাতায় যা আছে, আর তফাত।
     *
     * @return array{as_of: string, buckets: list<array{key: string, due: string, rate: string, loss: string, history: ?string}>, target: string, held: string, adjustment: string}
     */
    public function position(string $asOf): array
    {
        $this->assertWholeCompany();

        $totals = $this->reports->run('customer.ageing', ['from' => '2000-01-01', 'to' => $asOf], 1, 1)->totals;

        $buckets = [];
        $target = '0';
        $history = $this->history($asOf);
        foreach (self::RATES as $key => $setting) {
            $due = Money::round((string) ($totals[$key] ?? '0'), 4);
            $rate = $this->rate($key);
            $loss = Money::round(bcdiv(bcmul($due, $rate, 8), '100', 8), 2);
            $buckets[] = ['key' => $key, 'due' => $due, 'rate' => $rate, 'loss' => $loss, 'history' => $history[$key] ?? null];
            $target = bcadd($target, $loss, 2);
        }

        $held = $this->allowanceHeld($asOf);

        return ['as_of' => $asOf, 'buckets' => $buckets, 'target' => $target, 'held' => $held, 'adjustment' => bcsub($target, $held, 2)];
    }

    /** ভাতার খাতে তারিখ পর্যন্ত জমা (Cr − Dr, ধনাত্মক = ভাতা আছে) — গোটা কোম্পানি */
    public function allowanceHeld(string $asOf): string
    {
        $account = $this->account(StandardChart::LOSS_ALLOWANCE);

        $net = DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->where('account_id', $account->id)
            ->where('trx_date', '<=', $asOf)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as n')
            ->value('n');

        return Money::round((string) $net, 2);
    }

    /**
     * ⭐ ইতিহাসের হার, প্রতিটা ভাগে — এক বছর আগে (`asOf` − 12 মাস) প্রতিটা গ্রাহকের প্রতিটা ভাগে যা বকেয়া ছিল, তার কত অংশ পরের এক
     * বছরে অনাদায়ী বলে বাদ গেল (পাকা বাদের ভাউচার: Cr পাওনা গ্রাহকের নামে, একই ভাউচারে Dr ভাতা বা ক্ষতির খরচ)।
     * ⓘ বাদ সবচেয়ে পুরনো ভাগ থেকে কাটা হয় (আদায়ের FIFO-র একই নিয়ম), সেই গ্রাহকের সেদিনের বকেয়া পর্যন্ত।
     * ⓘ সেদিন ভাগে কোনো বকেয়া না থাকলে `null` — পর্দায় "—", কারণ শূন্য বললে মনে হত "কখনো ক্ষতি হয় না"।
     *
     * @return array<string, ?string>  ভাগ => শতাংশ (দুই ঘর) বা null
     */
    public function history(string $asOf): array
    {
        $from = CarbonImmutable::parse($asOf)->subMonthsNoOverflow(self::HISTORY_MONTHS)->toDateString();
        $rows = $this->reports->run('customer.ageing', ['from' => '2000-01-01', 'to' => $from], 1, 100000)->rows;

        $written = $this->writtenOffByCustomer($from, $asOf);
        $oldestFirst = array_reverse(array_keys(self::RATES));
        $base = array_fill_keys(array_keys(self::RATES), '0');
        $lost = $base;

        foreach ($rows as $row) {
            $row = (array) $row;
            $left = $written[(int) ($row['party_id'] ?? 0)] ?? '0';

            foreach ($oldestFirst as $key) {
                $due = Money::round((string) ($row[$key] ?? '0'), 4);
                $base[$key] = bcadd($base[$key], $due, 4);
                $take = bccomp($left, $due, 4) > 0 ? $due : $left;
                $lost[$key] = bcadd($lost[$key], $take, 4);
                $left = bcsub($left, $take, 4);
            }
        }

        $rates = [];
        foreach ($base as $key => $due) {
            $rates[$key] = bccomp($due, '0', 4) > 0 ? Money::round(bcdiv(bcmul($lost[$key], '100', 8), $due, 8), 2) : null;
        }

        return $rates;
    }

    /**
     * পাকা বাদের অঙ্ক, গ্রাহক ধরে — (from, to]-এ Cr পাওনা (গ্রাহকের নামে) যে ভাউচারে Dr ভাতা বা ক্ষতির খরচও আছে।
     *
     * @return array<int, string>
     */
    private function writtenOffByCustomer(string $from, string $to): array
    {
        $receivable = StandardChart::find(StandardChart::RECEIVABLE);
        $lossAccounts = array_values(array_filter([
            StandardChart::find(StandardChart::LOSS_ALLOWANCE)?->id,
            StandardChart::find(StandardChart::IMPAIRMENT_LOSS)?->id,
        ]));

        if ($receivable === null || $lossAccounts === []) {
            return [];
        }

        return DB::table('ledger_entries as r')
            ->where('r.company_id', CompanyContext::id())
            ->where('r.account_id', $receivable->id)
            ->where('r.party_type', Customer::drillSourceType())
            ->where('r.credit', '>', 0)
            ->where('r.trx_date', '>', $from)
            ->where('r.trx_date', '<=', $to)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries as l')
                ->whereColumn('l.source_type', 'r.source_type')
                ->whereColumn('l.source_id', 'r.source_id')
                ->whereIn('l.account_id', $lossAccounts)
                ->where('l.debit', '>', 0))
            ->groupBy('r.party_id')
            ->selectRaw('r.party_id, SUM(r.credit) - SUM(r.debit) as n')
            ->pluck('n', 'party_id')
            ->map(fn ($n) => Money::round((string) $n, 4))
            ->all();
    }

    /**
     * ⭐ সংরক্ষণের জাবেদা অনুমোদনে — জাবেদার সইয়ের ছক থাকলে অনুরোধ যায় ([[VoucherApproval::stopping()]], পোস্টের একই প্রশ্ন);
     * ছক না থাকলে `null`, আর খসড়াটা লেখক ছাড়া অন্য কারও হাতে পাকা হয় (লেখক ≠ পাকাকারী)। ⛔ এখানে কখনো পোস্ট হয় না।
     */
    public function sendForApproval(Voucher $voucher): ?Approval
    {
        return app(VoucherApproval::class)->stopping($voucher);
    }

    /** ⭐ তফাতের খসড়া জাবেদা — পাকা হয় ভাউচারের নিজের পথে */
    public function draftProvision(string $asOf): Voucher
    {
        $position = $this->position($asOf);
        $adjustment = $position['adjustment'];

        if (bccomp($adjustment, '0', 2) === 0) {
            throw ValidationException::withMessages(['as_of' => __('credit_control::loss.nothing_to_adjust')]);
        }

        $loss = $this->account(StandardChart::IMPAIRMENT_LOSS);
        $allowance = $this->account(StandardChart::LOSS_ALLOWANCE);
        $amount = ltrim($adjustment, '-');
        $up = bccomp($adjustment, '0', 2) > 0;
        $narration = __('credit_control::loss.provision_narration', [
            'date' => $asOf, 'target' => Money::format($position['target']), 'held' => Money::format($position['held']),
        ]);

        return $this->vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $asOf, 'narration' => $narration],
            [
                ['account_id' => $up ? $loss->id : $allowance->id, 'debit' => $amount, 'credit' => '0', 'narration' => $narration],
                ['account_id' => $up ? $allowance->id : $loss->id, 'debit' => '0', 'credit' => $amount, 'narration' => $narration],
            ],
        );
    }

    /** গ্রাহকের তারিখ পর্যন্ত বকেয়া (Dr − Cr) — গোটা কোম্পানি */
    public function dueOf(Customer $customer, string $asOf): string
    {
        $net = LedgerEntry::query()
            ->where('party_type', Customer::drillSourceType())
            ->where('party_id', $customer->id)
            ->whereDate('trx_date', '<=', $asOf)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')
            ->value('n');

        return Money::round((string) $net, 2);
    }

    /** ⭐ অনাদায়ী বাদের খসড়া জাবেদা — Cr পাওনা (গ্রাহকের নামে), Dr ভাতা যতটা আছে, বাকি Dr ক্ষতির খরচ */
    public function draftWriteOff(Customer $customer, string $amount, string $reason, string $asOf): Voucher
    {
        $this->assertWholeCompany();
        $amount = Money::round($amount, 2);
        $reason = trim($reason);

        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => __('credit_control::loss.amount_positive')]);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('credit_control::loss.reason_required')]);
        }

        $due = $this->dueOf($customer, $asOf);
        if (bccomp($amount, $due, 2) > 0) {
            throw ValidationException::withMessages(['amount' => __('credit_control::loss.more_than_due', ['due' => Money::format($due)])]);
        }

        $held = $this->allowanceHeld($asOf);
        $fromAllowance = bccomp($held, $amount, 2) >= 0 ? $amount : (bccomp($held, '0', 2) > 0 ? $held : '0');
        $toExpense = bcsub($amount, $fromAllowance, 2);
        $narration = __('credit_control::loss.write_off_narration', ['name' => $customer->name(), 'reason' => $reason]);

        $lines = [];
        if (bccomp($fromAllowance, '0', 2) > 0) {
            $lines[] = ['account_id' => $this->account(StandardChart::LOSS_ALLOWANCE)->id, 'debit' => $fromAllowance, 'credit' => '0', 'narration' => $narration];
        }
        if (bccomp($toExpense, '0', 2) > 0) {
            $lines[] = ['account_id' => $this->account(StandardChart::IMPAIRMENT_LOSS)->id, 'debit' => $toExpense, 'credit' => '0', 'narration' => $narration];
        }
        $lines[] = [
            'account_id' => $this->account(StandardChart::RECEIVABLE)->id, 'debit' => '0', 'credit' => $amount, 'narration' => $narration,
            'party_type' => Customer::drillSourceType(), 'party_id' => $customer->id,
        ];

        // ⓘ মাথায়ও গ্রাহক — তালিকায় নম্বরের পাশে পক্ষ (মালিকের নিয়ম, ১৯ অক্টোবর ২০২৬)
        return $this->vouchers->create([
            'type' => Voucher::JOURNAL, 'trx_date' => $asOf, 'narration' => $narration,
            'party_type' => Customer::drillSourceType(), 'party_id' => $customer->id,
        ], $lines);
    }

    /** ⓘ কোম্পানির হার; বসানো না থাকলে মালিকের ডিফল্ট ([[DEFAULTS]]); ঋণাত্মক বা অর্থহীন = 0 */
    public function rate(string $bucket): string
    {
        $value = (string) $this->settings->get(self::RATES[$bucket], self::DEFAULTS[$bucket]);

        return is_numeric($value) && bccomp($value, '0', 4) > 0 ? bcadd($value, '0', 4) : '0';
    }

    private function account(string $code): Account
    {
        $account = StandardChart::find($code);

        if ($account === null) {
            throw ValidationException::withMessages(['as_of' => __('credit_control::loss.account_missing', ['code' => $code])]);
        }

        return $account;
    }

    private function assertWholeCompany(): void
    {
        if (app(DataScope::class)->idsFor(auth()->user(), UserDataScope::BRANCH) !== null) {
            throw ValidationException::withMessages(['as_of' => __('credit_control::loss.whole_company_only')]);
        }
    }
}
