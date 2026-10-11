<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\YearEndService;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\MasterData\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * লাভ বণ্টন — ঘোষণা, তারপর পাওনা।
 *
 * ── ⭐ মালিকের নকশা, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * প্রশ্নটা ছিল সোজা: বণ্টন করা লাভ ব্যবসায় থাকবে, নাকি তাঁরা তুলে
 * নেবেন? ⓘ উত্তর: *"টাকাটা তুলে নেবেন"*, আর *"র থাকলে বছর শেষে
 * capital-এ যোগ হবে বা invest-এ"*।
 *
 * তাই তিনটা ধাপ, আর তিনটাই আলাদা ঘটনা:
 *
 *     ঘোষণা      সঞ্চিত মুনাফা (৩৩০০) → প্রদেয় মুনাফা (২১৯০)
 *     তোলা       প্রদেয় মুনাফা (২১৯০) → নগদ/ব্যাংক
 *     বছর শেষে   যা বাকি (২১৯০)       → মূলধন (৩১০০)
 *
 * ── ⛔ কেন সরাসরি মূলধনে নয় ────────────────────────────────────────
 * বসালে অঙ্কটা ব্যবসাতেই থেকে যেত, আর পরে টাকা তুললে খাতা সেটাকে
 * **মূলধন প্রত্যাহার** বলত — অর্থাৎ বলত মালিক ব্যবসা থেকে পুঁজি
 * সরাচ্ছেন, অথচ তিনি নিজের লাভ নিচ্ছেন। ⚠️ দুইটা সম্পূর্ণ আলাদা কথা,
 * আর অংশীদারি ব্যবসায় ওই পার্থক্যটাই পরে ঝগড়া হয়।
 *
 * ── ⓘ ভাগটা এক জায়গাতেই হয় ────────────────────────────────────────
 * অনুপাত ও পয়সা বের হয় [[CapitalService::positions()]] থেকে, আর সে
 * নিজে [[ProfitSplit]] ব্যবহার করে। ⚠️ এখানে আবার গুণ-ভাগ করলে দুই
 * পর্দায় দুই রকম পয়সা দেখাত — ঠিক যে ভুলটা ২০ সেপ্টেম্বরে সারানো
 * হয়েছে।
 */
final class ProfitDistribution
{
    use ReadsTheRowUnderLock;

    /** সই-এর ছকে এই কাজের নাম — `module.php`-র `approvals`/`moves_money`-তে একই বানান। */
    public const MODULE = 'finance';

    public const ACTION = 'profit';

    public function __construct(
        private readonly CapitalService $capital,
        private readonly NumberSeriesEngine $numbers,
        private readonly VoucherService $vouchers,
        private readonly DocumentApproval $approval,

        // ⛔ লাভকে মূলধনে নেওয়াও সই ছাড়া নয় — অডিট গ১৪, ৪ অক্টোবর ২০২৬
        private readonly FinanceSignature $signature,
    ) {}

    /**
     * কে কত পাবেন — ঘোষণার আগে দেখার জন্য।
     *
     * ⓘ কোনো সারি লেখা হয় না, কিছুই খাতায় বসে না। ⚠️ পর্দায় সংখ্যাটা
     * দেখে মালিক অনুপাত বদলাতে পারেন, আর সেটাই এই মেথডের গোটা কারণ।
     *
     * @return list<array{person_id: int, name: string, share: string|null, amount: string}>
     */
    public function preview(string $profit): array
    {
        $this->assertPositive($profit);

        $out = [];

        foreach ($this->capital->positions($profit, wholeCompany: true) as $position) {
            $amount = (string) ($position['profit_share'] ?? '0');

            /*
             * ⛔ শূন্য ভাগের সারি বাদ — যাঁর বাকি মূলধনই নেই তিনি
             * লাভের ভাগ পান না। ⓘ সারিটা রাখলে ঘোষণার কাগজে শূন্য
             * টাকার লাইন বসত, আর পড়ে মনে হত তাঁকে কিছু দেওয়া হয়েছে।
             */
            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            $out[] = [
                'person_id' => (int) $position['person_id'],
                'name' => (string) $position['name'],
                'share' => $position['share'] === null ? null : (string) $position['share'],
                'amount' => $amount,
            ];
        }

        return $out;
    }

    /**
     * ঘোষণা — ভাগগুলো লেখা হয়, আর খাতায় দায় হয়ে বসে।
     *
     * ── ⚠️ একটাই ভাউচার, যতজনই থাকুন ───────────────────────────────
     * ডেবিট এক লাইনে (মোট), ক্রেডিট প্রতিজনের নামে আলাদা লাইনে।
     * ⓘ প্রতিজনের জন্য আলাদা ভাউচার বানালে একই সিদ্ধান্ত পাঁচটা কাগজ
     * হয়ে যেত, আর একটা বাতিল করলে বাকিগুলো রয়ে যেত — অর্থাৎ অর্ধেক
     * বণ্টন, যা কোনো অবস্থাই নয়।
     *
     * @param  array{trx_date: string, profit: string, narration?: string|null}  $data
     * @return list<ProfitShare>
     */
    public function declare(array $data): array
    {
        $profit = (string) $data['profit'];

        $this->assertPositive($profit);
        $this->assertSharesWithinWhole();

        $rows = $this->preview($profit);

        if ($rows === []) {
            throw ValidationException::withMessages([
                'profit' => __('finance::validation.nobody_has_a_share'),
            ]);
        }

        /*
         * ⛔ সীমা মাপা হয় যা খাতায় যাবে তার উপর — অডিট গ১৩, ৪ অক্টোবর ২০২৬।
         *
         * ⓘ আগে সীমা দেখা হত চাওয়া অঙ্কে, অথচ খাতায় যেত ভাগগুলোর যোগফল। অংশের যোগ ১০০ পেরোলে (৬০ + ৬০)
         * বা একজন দুই সারিতে থাকলে ১০ লাখের ঘোষণায় ১২ লাখ বেরোত — সঞ্চিত মুনাফার চেয়েও বেশি। ⭐ এখন
         * যোগফলটাই মাপা হয়, আর সেটা চাওয়ার বেশি হলে ঘোষণাই হয় না।
         */
        $total = $this->totalOf($rows);

        if (bccomp($total, $profit, 4) > 0) {
            throw ValidationException::withMessages([
                'profit' => __('finance::validation.shares_pay_more_than_declared', [
                    'asked' => $profit,
                    'total' => $total,
                ]),
            ]);
        }

        $this->assertWithinRetainedProfit($total);

        return DB::transaction(function () use ($data, $profit, $rows, $total) {
            $retained = $this->account(StandardChart::RETAINED_EARNINGS);

            /*
             * ⛔ সঞ্চিত মুনাফার খাতে তালা, তারপর সীমা আবার — চূড়ান্ত অডিট ⛔১১, ৩০ সেপ্টেম্বর ২০২৬
             * ([[TwoAtOnceBrokeAFinanceCeilingTest]])। ⓘ উপরের যাচাই লেনদেনের বাইরে: দুইজন একসাথে
             * ৬০,০০০ করে ঘোষণা করলে দুইজনেই "১,০০,০০০ আছে" দেখতেন, আর যা আয়ই হয়নি তাও ভাগ হত।
             */
            Account::query()->whereKey($retained->id)->lockForUpdate()->first();
            $this->assertWithinRetainedProfit($total);
            $payable = $this->account(StandardChart::PROFIT_PAYABLE);

            $documentNo = $this->numbers->next('PDS');

            /*
             * ⭐ মোটটা ভাগগুলোর যোগফল, চাওয়া অঙ্কটা নয়।
             *
             * ⛔ [[ProfitSplit]] বড়-অবশিষ্ট নিয়মে ভাগ করে, তাই যোগফল
             * চাওয়া অঙ্কেরই সমান হয় — কিন্তু শূন্য-ভাগের সারি বাদ
             * দেওয়ার পরে নয়। ⚠️ চাওয়া অঙ্কটা ডেবিটে বসালে দাখিলাটা
             * মিলত না, আর ভাউচার পোস্টই হত না।
             */
            $lines = [
                ['account_id' => $retained->id, 'debit' => $total, 'credit' => '0'],
            ];

            foreach ($rows as $row) {
                $lines[] = [
                    'account_id' => $payable->id,
                    'debit' => '0',
                    'credit' => $row['amount'],

                    /*
                     * ⓘ পক্ষটা লাইনেই বসে, তাই খতিয়ান থেকেও "কার কত"
                     * বের করা যায় — আর সেটা নিচের সারিগুলোর সাথে
                     * মিলিয়ে দেখার একমাত্র উপায়।
                     */
                    'party_type' => 'person',
                    'party_id' => $row['person_id'],
                ];
            }

            $voucher = $this->vouchers->create([
                'type' => Voucher::JOURNAL,
                'branch_id' => $this->companyBranch(),
                'trx_date' => $data['trx_date'],
                'narration' => $data['narration'] ?? __('finance::message.profit_narration', [
                    'no' => $documentNo,
                ]),
            ], $lines);

            /*
             * ⛔ সই ছাড়া লাভ ভাগ নয় — চেকলিস্ট (অডিট ২৭ সেপ্টেম্বর) ঘর ৪, ১ অক্টোবর ২০২৬
             * ([[AProfitWasSharedWithNobodyToSignTest]])। মালিকের নিয়ম: *যেকোনো টাকা, যেকোনো অঙ্ক —
             * সই লাগে*। ছক থাকলে ভাউচার আর ভাগগুলো খসড়া থাকে; শেষ সই পড়লে
             * [[PostTheProfitOnTheLastSignature]] নিজে খাতায় বসায় ([[finishSigned()]])।
             * ⓘ ছক না থাকলে (সুইচ বন্ধ) আগের মতো সাথে সাথে।
             */
            $held = $this->approval->stopping($voucher, self::MODULE, self::ACTION, $total) !== null;

            if (! $held) {
                $this->vouchers->post($voucher);
            }

            $shares = [];

            foreach ($rows as $row) {
                $shares[] = ProfitShare::query()->create([
                    // ⭐ ঘোষণার শাখা — তালিকার শাখার দেয়ালের জন্য (১ অক্টোবর ২০২৬); ভাউচারের একই শাখা ([[companyBranch()]])
                    'branch_id' => $this->companyBranch(),
                    'document_no' => $documentNo,
                    'trx_date' => $data['trx_date'],
                    'person_id' => $row['person_id'],
                    'share_percent' => $row['share'],
                    'profit_base' => $profit,
                    'amount' => $row['amount'],
                    'status' => $held ? ProfitShare::DRAFT : ProfitShare::POSTED,
                    'voucher_id' => $voucher->id,
                    'narration' => $data['narration'] ?? null,
                    'posted_at' => $held ? null : now(),
                ]);
            }

            return $shares;
        });
    }

    /**
     * ⭐ লাভের ঘোষণা আর মূলধনে নেওয়া কোন শাখায় বসে — কোম্পানির প্রধান শাখায়, হেডারে যে শাখাই বাছা থাকুক।
     *
     * ⛔ পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬: দুইটাই হেডারের শাখায় বসত। লাভ ভাগ পুরো কোম্পানির
     * ([[TheProfitIsSharedOverTheWholeCompanyTest]]), অথচ ২১৯০ আর ৩৩০০-এর সারি বসত যে শাখা হেডারে ছিল সেখানে — এক ঘোষণা
     * এক শাখায়, তার মূলধনে নেওয়া আরেক শাখায়, আর শাখা ধরে ২১৯০ কখনো শূন্যে নামত না। ⓘ এ কাগজের নিজের কোনো শাখা নেই
     * (কোম্পানি-স্তরের সিদ্ধান্ত), তাই সবসময় একটাই জায়গা: প্রধান শাখা ([[Company::defaultBranch()]])।
     */
    private function companyBranch(): ?int
    {
        $branch = Company::query()->find(CompanyContext::id())?->defaultBranch();

        return $branch?->id ?? CompanyContext::branchId();
    }

    /**
     * ⭐ যত সঞ্চিত মুনাফা আছে, তার বেশি ঘোষণা নয় — নিরীক্ষা §২।
     *
     * ── ⛔ কী ঘটত ───────────────────────────────────
     * ঘোষণা কেবল দেখত অংকটা ধনাত্মক কি না। ⚠️ তাই যা অর্জিত হয়নি
     * তাও ঘোষণা করা যেত: সঞ্চিত মুনাফার খাতটা ডেবিটে নেমে যেত,
     * আর খাতা তবু ভারসাম্যে থাকত — দাখিলাটা নিজে মিলে।
     *
     * ⛔ অর্থাৎ না-থাকা লাভ ভাগ হয়ে যেত, আর কোনো পরীক্ষা লাল হত না।
     *
     * ── ⓘ আর এটাই "দুইবার নয়"-এর আসল রক্ষা ───────────────
     * ⭐ ঘোষণার পর সঞ্চিত মুনাফা কমে যায়। ⓘ তাই একই অংক দ্বিতীয়বার
     * ঘোষণা করতে গেলে এই পাহারাটাই থামায় — আলাদা কোনো নিয়ম
     * আবিষ্কার করতে হয় না।
     *
     * ⚠️ তবে এটা "বছরে একটাই ঘোষণা" বলে না, আর বলা উচিতও নয় —
     * বছরে দুইবার লাভ বাঁটা স্বাভাবিক। ⓘ পাহারাটা টাকার সীমা
     * ধরে, বচনের সংখ্যা ধরে নয়।
     *
     * ── ⓘ চিহ্নটা মেপে নেওয়া ────────────────────────────
     * [[Account::balanceOn()]] খাতের **স্বাভাবিক দিকে** ফেরায় — ক্রেডিট
     * প্রকৃতির খাতে `credit − debit`। ⭐ তাই সঞ্চিত মুনাফা থাকলে সংখ্যাটা
     * ধনাত্মক। ⚠️ চিহ্ন উল্টো ধরলে পাহারাটা হয় সব আটকাত,
     * নয় কিছুই আটকাত না — তাই কোড পড়ে নিশ্চিত হওয়া।
     */
    private function assertWithinRetainedProfit(string $profit): void
    {
        $available = $this->available();

        if (bccomp($profit, $available, 4) > 0) {
            throw ValidationException::withMessages([
                'profit' => __('finance::validation.more_than_retained', [
                    'asked' => $profit,
                    'have' => $available,
                ]),
            ]);
        }
    }

    /**
     * ⛔ চুক্তির অংশের যোগ ১০০-র বেশি নয় — অডিট গ১৩, ৪ অক্টোবর ২০২৬।
     *
     * ⓘ ৬০% আর ৬০% লেখা থাকলে ভাগ বসত চাওয়ার ১২০% — একজনের টাকা আরেকজনের নামে নয়, এমন টাকা যা নেই।
     * ⚠️ এটা বণ্টন থামায়, মূলধনের সারি নয়: শোধরাতে হয় চুক্তির অংশ, আর সেটা মানুষের সিদ্ধান্ত।
     */
    private function assertSharesWithinWhole(): void
    {
        $agreed = '0';

        foreach ($this->capital->positions(wholeCompany: true) as $position) {
            if (($position['share_source'] ?? null) === 'agreed') {
                $agreed = bcadd($agreed, (string) $position['share'], 4);
            }
        }

        if (bccomp($agreed, '100', 4) > 0) {
            throw ValidationException::withMessages([
                'profit' => __('finance::validation.shares_over_a_hundred', ['total' => $agreed]),
            ]);
        }
    }

    /** @param  list<array{amount: string}>  $rows */
    private function totalOf(array $rows): string
    {
        $total = '0';

        foreach ($rows as $row) {
            $total = bcadd($total, $row['amount'], 4);
        }

        return $total;
    }

    /**
     * ⭐ ফল থেকে বণ্টনযোগ্য মুনাফা — আয় বিয়োগ ব্যয় (বন্ধ আর চলতি সব বছর), বিয়োগ আগের ঘোষণা।
     *
     * ── ⛔ কেন ৩৩০০-এর কাঁচা জের নয় (অডিট গ১৩, ৪ অক্টোবর ২০২৬) ─────────────
     * খোলা জেরের সমতার অঙ্ক — খোলা মজুদ, চলতি ঋণের খোলা বকেয়া, স্থায়ী সম্পদ — সবই সঞ্চিত মুনাফায় বসে
     * ([[OpeningBalanceService]], [[BankFacilityService::openingFor()]])। তাই নতুন কোম্পানি ৫০ লাখের খোলা
     * মজুদকে "লাভ" ঘোষণা করে নগদে তুলে নিতে পারত। ⓘ এই মাপে কেবল সত্যিকারের ফল: আয়-ব্যয়ের খাতের সব
     * সারি, বছর বন্ধের দাখিলা বাদ (ওটা কেবল ফলটাকে ৩৩০০-এ সরায়, [[YearEndService::closingSources()]])।
     *
     * ── ⚠️ মালিকের সিদ্ধান্ত বাকি — তাই এখনো সীমা নয় ───────────────────────
     * ABOS-এর আগের বছরগুলোর সত্যিকারের সঞ্চিত মুনাফাও খোলা জেরেই এসেছে, আর এই মাপে সেটা নেই — অর্থাৎ
     * এটা সীমা হলে পুরনো লাভ আর কোনোদিন বাঁটা যেত না। আর চলতি বছরের না-বন্ধ লাভও এই মাপে আছে, যা আজকের
     * সীমায় নেই (মধ্য-বছরের বণ্টন)। দুইটাই মালিকের কথা; তাঁর উত্তর এলে [[available()]] এটা ডাকবে।
     */
    public function distributableFromResults(): string
    {
        $results = (string) (LedgerEntry::query()
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.company_id', CompanyContext::id())
            ->whereIn('accounts.type', [Account::INCOME, Account::EXPENSE])
            ->whereNotIn('ledger_entries.source_type', YearEndService::closingSources())
            ->selectRaw('COALESCE(SUM(ledger_entries.credit) - SUM(ledger_entries.debit), 0) as net')
            ->value('net') ?? '0');

        $declared = (string) (ProfitShare::query()
            ->whereIn('status', [ProfitShare::POSTED, ProfitShare::DRAFT])
            ->whereHas('voucher', fn ($v) => $v->where('status', '!=', DocumentStatus::CANCELLED))
            ->sum('amount') ?: '0');

        return bcsub($results, $declared, 4);
    }

    /**
     * সঞ্চিত মুনাফা থেকে সই-এর অপেক্ষায় থাকা ঘোষণাগুলো বাদ দিয়ে যা ঘোষণা করা যায়।
     *
     * ⛔ অপেক্ষার ঘোষণা খাতায় বসেনি, তাই জের তাকে দেখে না — না কাটলে দুইটা খসড়া মিলে
     * আয়ের বেশি ভাগ হত, আর দুইটাই সই পেলে না-অর্জিত লাভ বণ্টন হত। ⓘ যেটা সই পেয়ে বসছে
     * সেটা নিজেকে বাদ দেয় ([[finishSigned()]])।
     */
    private function available(?int $exceptVoucherId = null): string
    {
        $waiting = (string) (ProfitShare::query()
            ->where('status', ProfitShare::DRAFT)
            ->when($exceptVoucherId !== null, fn ($q) => $q->where('voucher_id', '!=', $exceptVoucherId))
            ->sum('amount') ?: '0');

        return bcsub($this->account(StandardChart::RETAINED_EARNINGS)->balanceOn(), $waiting, 4);
    }

    /**
     * ⭐ শেষ সই পড়ল — খসড়া ঘোষণাটা খাতায় ([[PostTheProfitOnTheLastSignature]])।
     *
     * ⓘ সই আর খাতার মাঝে সঞ্চিত মুনাফা কমে যেতে পারে (লোকসানের ভাউচার, আরেকটা ঘোষণা)। তাই
     * সঞ্চিত মুনাফার খাতে তালা দিয়ে সীমা আবার দেখা হয়; ⛔ না খাটলে ঘোষণাটা বাতিল হয়, খাতায় কিছু
     * বসে না। সই মানুষের সিদ্ধান্ত, সেটা ফেরে না ([[ApprovalDecided]]) — কিন্তু না-অর্জিত লাভ ভাগও হয় না।
     */
    public function finishSigned(Voucher $voucher): void
    {
        $refused = DB::transaction(function () use ($voucher): ?string {
            $retained = $this->account(StandardChart::RETAINED_EARNINGS);
            Account::query()->whereKey($retained->id)->lockForUpdate()->first();
            $this->lockFresh($voucher);

            $shares = ProfitShare::query()
                ->where('voucher_id', $voucher->id)
                ->where('status', ProfitShare::DRAFT)
                ->lockForUpdate()
                ->get();

            if ($shares->isEmpty() || $voucher->isPosted() || $voucher->isCancelled()) {
                return null;
            }

            $total = '0';

            foreach ($shares as $share) {
                $total = bcadd($total, (string) $share->amount, 4);
            }

            $have = $this->available((int) $voucher->id);

            if (bccomp($total, $have, 4) > 0) {
                $reason = __('finance::validation.profit_no_longer_covered', ['asked' => $total, 'have' => $have]);
                $this->drop($voucher, $reason);

                return $reason;
            }

            $this->vouchers->post($voucher);

            ProfitShare::query()->whereKey($shares->modelKeys())->update([
                'status' => ProfitShare::POSTED,
                'posted_at' => now(),
            ]);

            return null;
        });

        if ($refused !== null) {
            Log::warning('A signed profit declaration was not posted', [
                'company_id' => (int) $voucher->company_id,
                'voucher_id' => (int) $voucher->id,
                'reason' => $refused,
            ]);
        }
    }

    /** ⭐ সইকারী "না" বললেন — খসড়া ঘোষণা বাতিল, অঙ্কটা আবার ঘোষণার জন্য ছাড়া পায়। */
    public function dropRefused(Voucher $voucher, string $reason): void
    {
        DB::transaction(function () use ($voucher, $reason): void {
            $this->lockFresh($voucher);

            if ($voucher->isPosted() || $voucher->isCancelled()) {
                return;
            }

            $this->drop($voucher, $reason);
        });
    }

    private function drop(Voucher $voucher, string $reason): void
    {
        $this->vouchers->cancel($voucher, $reason);

        ProfitShare::query()
            ->where('voucher_id', $voucher->id)
            ->where('status', ProfitShare::DRAFT)
            ->update(['status' => ProfitShare::CANCELLED]);
    }

    /**
     * এই মানুষের ঘোষিত মুনাফার কতটুকু এখনো তোলা হয়নি।
     *
     * ── ⓘ খাতিয়ান না, সারি ধরে ──────────────────────────
     * `2190` খাতের জের সবার মিলিত, আর প্রশ্নটা ব্যক্তির।
     * ⚠️ লাইনে পক্ষ বসানো আছে বলে খতিয়ান থেকেও বের করা যেত,
     * কিন্তু সারি দুইটাই নিজের টেবিলে আছে — ঘোষণা
     * [[ProfitShare]]-এ, তোলা [[Withdrawal]]-এ। ⓘ অনুমোদিত সারি
     * ধরে গোনাই এই রিপোর ছাঁচ ([[CapitalService::withdrawnBy]])।
     *
     * ⛔ খসড়া গোনা হয় না — দুই পাশেই। তাহলে না-বসা টাকা
     * দিয়ে দায় বাড়ত বা কমত।
     */
    public function outstandingFor(int $personId, ?int $exceptVoucherId = null): string
    {
        $declared = (string) (ProfitShare::query()
            ->posted()
            ->where('person_id', $personId)
            ->sum('amount') ?: '0');

        $taken = (string) (Withdrawal::query()
            ->posted()
            ->where('person_id', $personId)
            ->where('kind', Withdrawal::PROFIT_SHARE)
            ->sum('amount') ?: '0');

        /*
         * ⭐ মূলধনে যাওয়া অংশটাও আর পাওনা নয়।
         *
         * ⛔ এটা না বাদ দিলে একটা টাকা দুইবার দেওয়া যেত:
         * একবার মূলধনে যোগ হয়, তারপর আবার নগদে তোলা যেত —
         * আর ২১৯০-এর জের হয়ে যেত ঋণাত্মক, অর্থাৎ খাতা বলত
         * অংশীদার ব্যবসাকে টাকা দেবেন।
         */
        $capitalised = (string) (CapitalEntry::query()
            ->where('person_id', $personId)
            ->where('in_kind', CapitalEntry::PROFIT)

            /*
             * ⛔ সই-এর অপেক্ষায় থাকা মূলধনে-নেওয়াও আর পাওনা নয় — অডিট গ১৪, ৪ অক্টোবর ২০২৬। ⓘ না কাটলে
             * অপেক্ষার সময় একই টাকা নগদে তোলা যেত, বা আরেকবার মূলধনে নেওয়া যেত। শেষ সই-এ যেটা বসছে সেটা
             * নিজেকে বাদ দেয় ([[finishCapitalise()]])।
             */
            ->where(fn ($q) => $q->where('status', CapitalEntry::POSTED)
                ->orWhere(fn ($d) => $d->where('status', CapitalEntry::DRAFT)->whereNotNull('voucher_id')
                    ->when($exceptVoucherId !== null, fn ($e) => $e->where('voucher_id', '!=', $exceptVoucherId))))
            ->sum('amount') ?: '0');

        return bcsub(bcsub($declared, $taken, 4), $capitalised, 4);
    }

    /**
     * যাঁদের ঘোষিত ভাগ এখনো পড়ে আছে।
     *
     * ⓘ শূন্য বা ঋণাত্মক বাদ — বছর শেষে যাঁর কিছু বাকি নেই
     * তাঁকে তালিকায় রাখার মানে হয় না।
     *
     * @return list<array{person_id: int, name: string, amount: string}>
     */
    public function outstanding(): array
    {
        $ids = ProfitShare::query()
            ->posted()
            ->distinct()
            ->pluck('person_id')
            ->all();

        $people = Person::query()->whereKey($ids)->get()->keyBy('id');

        $out = [];

        foreach ($ids as $id) {
            $person = $people->get((int) $id);

            if ($person === null) {
                continue;
            }

            $left = $this->outstandingFor((int) $id);

            if (bccomp($left, '0', 4) <= 0) {
                continue;
            }

            $out[] = [
                'person_id' => (int) $id,
                'name' => $person->name(),
                'amount' => $left,
            ];
        }

        return $out;
    }

    /**
     * ⭐ বছর শেষে যা বাকি, তা মূলধনে।
     *
     * ── ⭐ মালিকের কথা, ২২ সেপ্টেম্বর ২০২৬ ──────────────
     * *"র থাকলে বছর শেষে capital-এ যোগ হবে বা invest-এ"*।
     *
     * ⓘ তাই ধরনটা বাছা যায় — অনুদান নাকি বিনিয়োগ।
     *
     * ── ⚠️ দাখিলায় নগদ কোথাও নেই ─────────────────────
     * Dr ২১৯০ / Cr ৩১০০ — একটা দায় মালিকানায় বদলাল, ব্যাংক বা
     * ক্যাশবাক্স নড়ল না। ⓘ তাই [[CapitalService::post()]] এখানে
     * খাটে না — সে একটা টাকার খাত চায়, আর এখানে সেটা নেই।
     *
     * ── ⓘ সারিও লেখা হয়, কেবল দাখিলা নয় ─────────────
     * [[CapitalService::positions()]] অংশ গোনে [[CapitalEntry]] সারি
     * ধরে। ⛔ কেবল খতিয়ানে লিখলে মূলধনে যোগ হওয়া লাভ
     * কারও **অংশ বাড়াত না**, আর পরের বছরের ভাগ ভুল হত।
     *
     * @param  array{trx_date: string, entry_type?: string, narration?: string|null}  $data
     * @return list<CapitalEntry>
     */
    public function capitalise(array $data): array
    {
        $rows = $this->outstanding();

        if ($rows === []) {
            throw ValidationException::withMessages([
                'trx_date' => __('finance::validation.nothing_left_to_capitalise'),
            ]);
        }

        $kind = ($data['entry_type'] ?? '') ?: CapitalEntry::CONTRIBUTION;

        if (! in_array($kind, CapitalEntry::KINDS, true)) {
            throw ValidationException::withMessages([
                'entry_type' => __('finance::validation.unknown_capital_kind'),
            ]);
        }

        return DB::transaction(function () use ($data, $kind) {
            $payable = $this->account(StandardChart::PROFIT_PAYABLE);
            $capital = $this->account(StandardChart::OWNER_CAPITAL);

            /*
             * ⛔ প্রদেয় মুনাফার খাতে তালা, তারপর বাকিটা আবার গোনা — অডিট গ১৪, ৪ অক্টোবর ২০২৬।
             *
             * ⓘ আগে বাকিটা গোনা হত লেনদেনের বাইরে, তালা ছাড়া: দুই চাপে দুইজনেই একই বাকি দেখতেন, আর মূলধন
             * দ্বিগুণ হত — প্রদেয় মুনাফা ঋণাত্মক। ⚠️ লাভের ভাগ তোলাও এই একই তালা নেয়
             * ([[WithdrawalService::post()]]), তাই তোলা আর মূলধনে নেওয়া একসাথে একই টাকা পায় না।
             */
            Account::query()->whereKey($payable->id)->lockForUpdate()->first();

            $rows = $this->outstanding();

            if ($rows === []) {
                throw ValidationException::withMessages([
                    'trx_date' => __('finance::validation.nothing_left_to_capitalise'),
                ]);
            }

            $lines = [];

            foreach ($rows as $row) {
                $lines[] = [
                    'account_id' => $payable->id,
                    'debit' => $row['amount'],
                    'credit' => '0',
                    'party_type' => 'person',
                    'party_id' => $row['person_id'],
                ];

                $lines[] = [
                    'account_id' => $capital->id,
                    'debit' => '0',
                    'credit' => $row['amount'],
                    'party_type' => 'person',
                    'party_id' => $row['person_id'],
                ];
            }

            /*
             * ⓘ নম্বরটা ভাউচারের, সারির নয় — কারণ বাতিলের
             * ঘটনাটা একটাই, আর সব কয়টা সারি তার সাথে যায়।
             */
            $voucher = $this->vouchers->create([
                'type' => Voucher::JOURNAL,
                'branch_id' => $this->companyBranch(),
                'trx_date' => $data['trx_date'],
                'narration' => $data['narration'] ?? __('finance::message.capitalise_narration'),
            ], $lines);

            /*
             * ⛔ সই ছাড়া মূলধনে নয় — অডিট গ১৪, ৪ অক্টোবর ২০২৬। ⓘ ছক থাকলে ভাউচার আর সারিগুলো খসড়া; শেষ সই
             * পড়লে [[finishCapitalise()]] তালার নিচে বাকিটা আবার দেখে খাতায় বসায়।
             */
            $held = $this->signature->postOrHold($voucher, FinanceSignature::CAPITALISE, $this->totalOf($rows));

            $entries = [];

            foreach ($rows as $row) {
                $entries[] = CapitalEntry::query()->create([
                    'branch_id' => $this->companyBranch(),

                    /*
                     * ⛔ প্রতিটা সারির নিজস্ব নম্বর।
                     *
                     * ⚠️ একটা নম্বর সবার গায়ে বসানো হয়েছিল
                     * ([[ProfitShare]]-এর মতো), আর তাতে দ্বিতীয়
                     * সারিটাই বসত না — `acc_capital_entries`-এ
                     * `document_no` কোম্পানিপ্রতি **ইউনিক**।
                     *
                     * ⓘ পার্থক্যটা ইচ্ছাকৃত: লাভের ভাগ **একটা
                     * ঘোষণা**র কয়েকটা লাইন, আর মূলধনের সারি
                     * প্রত্যেকটাই নিজে একটা নথি।
                     */
                    'document_no' => $this->numbers->next('PCAP'),
                    'person_id' => $row['person_id'],

                    /*
                     * ⓘ যিনি যে পরিচয়ে আগে মূলধন দিয়েছিলেন, সেই
                     * পরিচয়েই এই সারিটাও বসে। ⚠️ নিজে একটা ধরন
                     * বসালে [[CapitalService::positions()]] একজন মানুষকে
                     * দুই সারিতে দেখাত — সে `person_id`-এর সাথে
                     * `contributor_type`-ও ধরে দল বাঁধে।
                     */
                    'contributor_type' => $this->contributorType($row['person_id']),

                    'entry_type' => $kind,
                    'in_kind' => CapitalEntry::PROFIT,
                    'trx_date' => $data['trx_date'],
                    'amount' => $row['amount'],
                    'narration' => $data['narration'] ?? null,
                    'status' => $held ? CapitalEntry::DRAFT : CapitalEntry::POSTED,
                    'voucher_id' => $voucher->id,
                    'posted_at' => $held ? null : now(),
                    'created_by' => auth()->id(),
                ]);
            }

            return $entries;
        });
    }

    /**
     * ⭐ শেষ সই পড়ল — অপেক্ষার মূলধনে-নেওয়া খাতায় ([[FinishTheFinancePaperOnTheLastSignature]])।
     *
     * ⓘ সই আর খাতার মাঝে কেউ লাভের ভাগ তুলে থাকতে পারেন; তাই প্রদেয় মুনাফার খাতে তালা দিয়ে প্রতিজনের বাকি
     * আবার দেখা হয়। ⛔ না খাটলে বাতিল — সই মানুষের সিদ্ধান্ত, কিন্তু একই টাকা দুইবার দেওয়া যায় না।
     */
    public function finishCapitalise(Voucher $voucher): void
    {
        DB::transaction(function () use ($voucher): void {
            Account::query()->whereKey($this->account(StandardChart::PROFIT_PAYABLE)->id)->lockForUpdate()->first();
            $this->lockFresh($voucher);

            if (! $voucher->isDraft()) {
                return;
            }

            $entries = CapitalEntry::query()
                ->where('voucher_id', $voucher->id)
                ->where('status', CapitalEntry::DRAFT)
                ->where('in_kind', CapitalEntry::PROFIT)
                ->lockForUpdate()
                ->get();

            foreach ($entries as $entry) {
                $left = $this->outstandingFor((int) $entry->person_id, (int) $voucher->id);

                if (bccomp((string) $entry->amount, $left, 4) > 0) {
                    $this->dropCapitalised($voucher, __('finance::validation.profit_no_longer_covered', [
                        'asked' => (string) $entry->amount,
                        'have' => $left,
                    ]));

                    return;
                }
            }

            $this->vouchers->post($voucher);

            CapitalEntry::query()->whereKey($entries->modelKeys())->update([
                'status' => CapitalEntry::POSTED,
                'posted_at' => now(),
            ]);
        });
    }

    /** ⭐ সইকারী "না" বললেন — ভাউচার বাতিল, খসড়া সারিগুলো সরে যায়; পাওনাটা আবার পাওনা। */
    public function dropCapitalise(Voucher $voucher, string $reason): void
    {
        DB::transaction(function () use ($voucher, $reason): void {
            $this->lockFresh($voucher);

            if ($voucher->isDraft()) {
                $this->dropCapitalised($voucher, $reason);
            }
        });
    }

    private function dropCapitalised(Voucher $voucher, string $reason): void
    {
        $this->vouchers->cancel($voucher, $reason);

        CapitalEntry::query()
            ->where('voucher_id', $voucher->id)
            ->where('status', CapitalEntry::DRAFT)
            ->delete();
    }

    /**
     * এই মানুষটা আগে কিসের পরিচয়ে মূলধন দিয়েছেন।
     *
     * ⓘ না পাওয়া গেলে অংশীদার — লাভের ভাগ পাওয়া মানুষের
     * সবচেয়ে স্বাভাবিক পরিচয়।
     */
    private function contributorType(int $personId): string
    {
        return (string) (CapitalEntry::query()
            ->where('person_id', $personId)
            ->orderByDesc('id')
            ->value('contributor_type') ?: CapitalEntry::PARTNER);
    }

    /**
     * ⛔ শূন্য বা ঋণাত্মক মুনাফা ভাগ করা যায় না।
     *
     * ⚠️ লোকসানের বেলায় "ভাগ" কথাটারই অর্থ নেই — ওটা মূলধন খাওয়া,
     * আর সেটা আলাদা সিদ্ধান্ত। ⓘ [[ProfitSplit]] নিজেও ঋণাত্মক
     * ফেরায় না, তাই এখানে না আটকালে নিচে খালি তালিকা যেত আর
     * ভুলবার্তাটা অপ্রাসঙ্গিক হত।
     */
    private function assertPositive(string $profit): void
    {
        if (bccomp($profit, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'profit' => __('finance::validation.profit_must_be_positive'),
            ]);
        }
    }

    private function account(string $code): Account
    {
        /*
         * ⛔ `postable()` — দল-খাত বাদ, ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ আগে কেবল কোড মিলানো হত, আর যা আসত তাই নেওয়া
         * হত — দল হলেও। ⓘ দল-খাতে বসা জের খতিয়ানে দেখা যায়,
         * অথচ যোগফল থেকে নীরবে বাদ পড়ে — খাতা ঠিক দেখায়,
         * আর মেলে না।
         *
         * ⭐ ধরা পড়েছে `MoneyNeverLandsOnAGroupAccount`-এ।
         */
        $account = Account::query()->postable()->where('code', $code)->first();

        if ($account === null) {
            /*
             * ⚠️ খাতটা [[StandardChart]]-এ আছে, কিন্তু পুরনো কোম্পানিতে
             * বসেনি — `abos:sync-chart` চালালে বসে। ⓘ বার্তাটা সেটাই
             * বলে, নাহলে পর্দায় কেবল "not found" আসত।
             */
            throw ValidationException::withMessages([
                'profit' => __('finance::validation.chart_account_missing', ['code' => $code]),
            ]);
        }

        return $account;
    }
}
