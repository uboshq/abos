<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\MasterData\Models\Person;
use Illuminate\Support\Facades\DB;
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
    public function __construct(
        private readonly CapitalService $capital,
        private readonly NumberSeriesEngine $numbers,
        private readonly VoucherService $vouchers,
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

        foreach ($this->capital->positions($profit) as $position) {
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

        $rows = $this->preview($profit);

        if ($rows === []) {
            throw ValidationException::withMessages([
                'profit' => __('finance::validation.nobody_has_a_share'),
            ]);
        }

        return DB::transaction(function () use ($data, $profit, $rows) {
            $retained = $this->account(StandardChart::RETAINED_EARNINGS);
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
            $total = '0';

            foreach ($rows as $row) {
                $total = bcadd($total, $row['amount'], 4);
            }

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
                'trx_date' => $data['trx_date'],
                'narration' => $data['narration'] ?? __('finance::message.profit_narration', [
                    'no' => $documentNo,
                ]),
            ], $lines);

            $this->vouchers->post($voucher);

            $shares = [];

            foreach ($rows as $row) {
                $shares[] = ProfitShare::query()->create([
                    'document_no' => $documentNo,
                    'trx_date' => $data['trx_date'],
                    'person_id' => $row['person_id'],
                    'share_percent' => $row['share'],
                    'profit_base' => $profit,
                    'amount' => $row['amount'],
                    'status' => ProfitShare::POSTED,
                    'voucher_id' => $voucher->id,
                    'narration' => $data['narration'] ?? null,
                    'posted_at' => now(),
                ]);
            }

            return $shares;
        });
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
    public function outstandingFor(int $personId): string
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
            ->posted()
            ->where('person_id', $personId)
            ->where('in_kind', CapitalEntry::PROFIT)
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
     * @param array{trx_date: string, entry_type?: string, narration?: string|null} $data
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

        return DB::transaction(function () use ($data, $kind, $rows) {
            $payable = $this->account(StandardChart::PROFIT_PAYABLE);
            $capital = $this->account(StandardChart::OWNER_CAPITAL);

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
                'trx_date' => $data['trx_date'],
                'narration' => $data['narration'] ?? __('finance::message.capitalise_narration'),
            ], $lines);

            $this->vouchers->post($voucher);

            $entries = [];

            foreach ($rows as $row) {
                $entries[] = CapitalEntry::query()->create([
                    'branch_id' => CompanyContext::branchId(),

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
                    'status' => CapitalEntry::POSTED,
                    'voucher_id' => $voucher->id,
                    'posted_at' => now(),
                    'created_by' => auth()->id(),
                ]);
            }

            return $entries;
        });
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
