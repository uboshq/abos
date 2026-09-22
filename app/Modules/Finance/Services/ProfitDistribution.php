<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\Finance\Models\Withdrawal;
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

        return bcsub($declared, $taken, 4);
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
