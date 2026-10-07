<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * হিসাবের পাঁচ মিল — প্রতিটা লেনদেনের পরে যে পাঁচটা অঙ্ক মিলতে হবে।
 *
 * চেকলিস্ট §১ (`docs/Checklist — ব্যবসা চালু, সরাসরি ক্রয় ও বিক্রয়.md`),
 * মালিকের ২৭ সেপ্টেম্বর ২০২৬-এর নির্দেশ: *"business zehetu asol hisab
 * tai gormil holeei bipod"*। তাই পাঁচটা মিল একজায়গায়, আর প্রতিটা
 * প্রমাণ-পরীক্ষা একই ভাষায় সেগুলো দাবি করতে পারে:
 *
 *   ১ · খাতা          — ডেবিট = ক্রেডিট, আর প্রতিটা খাতের নড়াচড়া
 *                        হাতে গোনা অঙ্কের সমান
 *   ২ · মজুদ          — পরিমাণ আর মূল্য (FIFO স্তর), আর মজুদ খাতের
 *                        জের = স্তরের মোট মূল্য
 *   ৩ · পক্ষের বকেয়া  — গ্রাহক বা সরবরাহকারীর খতিয়ান = পাওনা বা দেনা
 *                        খাতের অংশ, আর অংশগুলো যোগ হয়ে পুরো খাত
 *   ৪ · নগদ ও ব্যাংক  — নগদ বাক্স, ব্যাংক আর বিকাশের জের = হাতে গোনা টাকা
 *   ৫ · লাভ           — বিক্রয় − বিক্রীত পণ্যের খরচ = মোট লাভ
 *
 * ── ⭐ সবচেয়ে জরুরি নকশার সিদ্ধান্ত: আসল অঙ্ক **কাঁচা টেবিল থেকে** ─────
 * এই ট্রেইট `CoreReports`, `ReportEngine`, `AccountsFacts`, `StockReports`
 * — একটাকেও ডাকে না। সে নিজের কোয়েরি দিয়ে `ledger_entries`,
 * `inv_stock_movements`, `inv_cost_layers` আর `accounts` পড়ে।
 *
 * ⛔ **কেন রিপোর্টকে জিজ্ঞেস করা যাবে না:** তাহলে বাগটা যদি রিপোর্টেই
 * থাকে, রিপোর্ট নিজের ভুলের সাথে নিজেই মিলে যেত আর মিলটা সবুজ দেখাত।
 * ⓘ ভুল রিপোর্ট আর ভুল খতিয়ান — ব্যবসার কাছে দুইটাই সমান বিপদ, আর
 * মালিক যে কাগজটা হাতে নিয়ে বসেন সেটা রিপোর্টই। ⭐ তাই আসল অঙ্ক আসে
 * টেবিল থেকে, আর হাতে গোনা অঙ্ক আসে পরীক্ষার নিজের হাত থেকে — মাঝখানে
 * অ্যাপের কোনো হিসাব-কষা কোড নেই।
 *
 * ⚠️ এর দাম আছে, আর সেটা জেনেই দেওয়া: রিপোর্টের নিয়ম বদলালে এই
 * কোয়েরিগুলো সেটা জানবে না। ⓘ সেটাই উদ্দেশ্য — দুইটা স্বাধীন সাক্ষী,
 * একটা অন্যটার নকল নয়।
 *
 * ── ⛔ টাকা কখনো float নয় ────────────────────────────────────────────
 * সব অঙ্ক bcmath স্ট্রিং, scale ৪। ⚠️ Eloquent-এর `->sum()` টাকার
 * কলামে ডাকা হয় না — সে float বানায় আর `MoneyIsNeverAFloatTest`-এর
 * নিষেধ ভাঙে; তার বদলে `selectRaw('COALESCE(SUM(...), 0)')` আর তুলনা
 * `bccomp(..., 4)`।
 *
 * ── ⓘ খাত কোড ধরে, আইডি ধরে নয় ───────────────────────────────────────
 * কোনো জায়গায় `1120` জাতীয় সংখ্যা হার্ডকোড করা নেই — কোড আসে
 * `StandardChart`-এর ধ্রুবক থেকে ([[StandardChart::INVENTORY]] ইত্যাদি)।
 * ⚠️ ছয় সেপ্টেম্বরের ২৫টা ত্রুটি ঠিক ওই হার্ডকোডিং থেকেই হয়েছিল
 * ([[Tests\RealAccounts]])।
 *
 * ── ⓘ গ্রুপ আর তার সন্তান একসাথে ─────────────────────────────────────
 * "খাতের নড়াচড়া" মানে খাতটা **আর তার সব সন্তানের** নড়াচড়া। ⚠️ নগদ
 * (`1101`) একটা গ্রুপ আর টাকা বসে till-এর সন্তানে; কেবল গ্রুপের নিজের
 * সারি গুনলে নগদ চিরকাল শূন্য দেখাত।
 */
trait ChecksTheFiveMatches
{
    /**
     * পাঁচটা মিল একসাথে — একটা লেনদেনের পরে একটাই ডাক।
     *
     * ⓘ প্রতিটা চাবি ঐচ্ছিক: যে মিলটার হাতে গোনা অঙ্ক দেওয়া হয়নি,
     * সেটা বাদ থাকে। ⚠️ কিন্তু `books` সবসময় চলে — ডেবিট = ক্রেডিট
     * এমন একটা দাবি যার জন্য কোনো হাতের অঙ্ক লাগে না, আর ওটা না
     * দেখলে বাকি চারটার কোনো মানেই থাকে না।
     *
     * @param  array{
     *     books?: array<string, string>,
     *     stock?: array<int, array{qty?: string, value?: string, free?: string}>,
     *     party?: list<array{type: string, id: int, code: string, amount: string}>,
     *     money?: array<string, string>,
     *     profit?: string
     * }  $plan
     */
    protected function assertTheFiveMatches(array $plan): void
    {
        $this->assertTheBooksMatch($plan['books'] ?? []);

        if (isset($plan['stock'])) {
            $this->assertTheStockMatches($plan['stock']);
        }

        foreach ($plan['party'] ?? [] as $party) {
            $this->assertThePartyOutstandingMatches(
                $party['type'], $party['id'], $party['code'], $party['amount'],
            );
        }

        if (isset($plan['money'])) {
            $this->assertTheMoneyMatches($plan['money']);
        }

        if (isset($plan['profit'])) {
            $this->assertTheGrossProfitMatches($plan['profit']);
        }
    }

    // ── ১ · খাতা ────────────────────────────────────────────────────────

    /**
     * মিল ১ — ডেবিট = ক্রেডিট, আর প্রতিটা খাতের নড়াচড়া হাতে গোনা অঙ্কের সমান।
     *
     * ⓘ `$movementsByCode`-এর অঙ্কটা **চিহ্নসহ ডেবিট − ক্রেডিট**। অর্থাৎ
     * মজুদ বাড়লে ধনাত্মক, বিক্রয় হলে (আয়, ক্রেডিট) ঋণাত্মক, দেনা বসলেও
     * ঋণাত্মক। ⚠️ "সব ধনাত্মক" নিয়মে লিখলে খাতের প্রকৃতি অনুমান করতে
     * হত, আর ভুল অনুমান নীরবে সবুজ দেখাত।
     *
     * ⓘ `$sourceType`/`$sourceId` দিলে ভারসাম্যটা কেবল ওই একটা কাগজের
     * দাখিলাগুলোর মধ্যে দেখা হয় — একটা বিল নিজে নিজে ভারসাম্যে আছে
     * কি না। না দিলে গোটা কোম্পানির খাতা।
     *
     * @param  array<string, string>  $movementsByCode  খাত-কোড => হাতে গোনা (ডেবিট − ক্রেডিট)
     */
    protected function assertTheBooksMatch(
        array $movementsByCode = [],
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): void {
        $query = DB::table('ledger_entries')->where('company_id', $this->fiveMatchCompanyId());

        if ($sourceType !== null) {
            $query->where('source_type', $sourceType);
        }

        if ($sourceId !== null) {
            $query->where('source_id', $sourceId);
        }

        $row = $query->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();

        $debit = $this->fiveMatchMoney($row->total_debit ?? '0');
        $credit = $this->fiveMatchMoney($row->total_credit ?? '0');

        $whose = $sourceType === null
            ? 'গোটা খাতা'
            : "কাগজ {$sourceType}#".($sourceId ?? '—');

        $this->assertSame(0, bccomp($debit, $credit, 4), $this->fiveMatchMessage(
            'মিল ১ · খাতা — ডেবিট = ক্রেডিট',
            "মোট ডেবিট ({$whose})",
            $debit,
            'মোট ক্রেডিট',
            $credit,
            'দুই পাশ সমান না হলে খাতাটাই ভাঙা — কোন কাগজ অসম, সেটা source_type ধরে খুঁজতে হবে।',
        ));

        foreach ($movementsByCode as $code => $expected) {
            $actual = $this->fiveMatchMovementOf((string) $code);

            $this->assertSame(0, bccomp($this->fiveMatchMoney($expected), $actual, 4), $this->fiveMatchMessage(
                'মিল ১ · খাতা — খাতের নড়াচড়া',
                "হাতে গোনা (খাত {$code} ".$this->fiveMatchAccountName((string) $code).', ডেবিট − ক্রেডিট)',
                $this->fiveMatchMoney($expected),
                'খতিয়ানে যা আছে',
                $actual,
            ));
        }
    }

    // ── ২ · মজুদ ────────────────────────────────────────────────────────

    /**
     * মিল ২ — মজুদের পরিমাণ ও মূল্য, আর মজুদ খাত = স্তরের মোট মূল্য।
     *
     * প্রতিটা পণ্যের জন্য তিনটা দাবি:
     *   `qty`   — তাকে আর গাড়িতে যা আছে (`floor_change + unplaced_change`)
     *   `value` — FIFO স্তরে অবশিষ্ট মালের দাম (`qty_remaining × unit_cost`)
     *   `free`  — ফ্রি ভাণ্ডারের পরিমাণ, চাইলে
     *
     * ⓘ আর একটা দাবি হাতের অঙ্ক ছাড়াই চলে: পণ্যের স্তরে অবশিষ্ট পরিমাণ =
     * তাকে থাকা পরিমাণ। ⚠️ ফ্রি মাল এই সমতায় ধরা হয় না, ইচ্ছাকৃত — ফ্রি
     * কার্টনের কোনো ক্রয়মূল্য নেই, তাই তার স্তরও নেই
     * ([[PurchaseBillService::bringInFree()]])।
     *
     * ⭐ শেষ দাবিটা গোটা কোম্পানির: মজুদ খাতের (`1120`) জের = সব স্তরের
     * মোট মূল্য। ⓘ এই দুইটা সংখ্যা কখনো একসাথে দেখা হত না, আর ঠিক সেই
     * কারণেই ডিপোর ৮,৪০,০০০ টাকার মাল একসময় খাতার বাইরে পড়ে ছিল।
     *
     * @param  array<int, array{qty?: string, value?: string, free?: string}>  $expected  পণ্যের আইডি => হাতে গোনা
     */
    protected function assertTheStockMatches(array $expected): void
    {
        foreach ($expected as $productId => $figures) {
            $productId = (int) $productId;

            if (isset($figures['qty'])) {
                $onHand = $this->fiveMatchStockOnHand($productId);

                $this->assertSame(0, bccomp($this->fiveMatchMoney($figures['qty']), $onHand, 4), $this->fiveMatchMessage(
                    'মিল ২ · মজুদ — পরিমাণ',
                    "হাতে গোনা (পণ্য #{$productId})",
                    $this->fiveMatchMoney($figures['qty']),
                    'গুদামে (floor + unplaced)',
                    $onHand,
                ));
            }

            if (isset($figures['free'])) {
                $free = $this->fiveMatchFreeOnHand($productId);

                $this->assertSame(0, bccomp($this->fiveMatchMoney($figures['free']), $free, 4), $this->fiveMatchMessage(
                    'মিল ২ · মজুদ — ফ্রি ভাণ্ডার',
                    "হাতে গোনা (পণ্য #{$productId})",
                    $this->fiveMatchMoney($figures['free']),
                    'ভাণ্ডারে',
                    $free,
                ));
            }

            if (isset($figures['value'])) {
                $value = $this->fiveMatchLayerValueOf($productId);

                $this->assertSame(0, bccomp($this->fiveMatchMoney($figures['value']), $value, 4), $this->fiveMatchMessage(
                    'মিল ২ · মজুদ — মূল্য (FIFO স্তর)',
                    "হাতে গোনা (পণ্য #{$productId})",
                    $this->fiveMatchMoney($figures['value']),
                    'স্তরে অবশিষ্ট মালের দাম',
                    $value,
                ));
            }

            // পরিমাণ আর স্তর — একই মাল, তাই একই সংখ্যা হতে বাধ্য
            $onHand = $this->fiveMatchStockOnHand($productId);
            $layerQty = $this->fiveMatchLayerQtyOf($productId);

            $this->assertSame(0, bccomp($onHand, $layerQty, 4), $this->fiveMatchMessage(
                'মিল ২ · মজুদ — স্তর আর তাক এক কথা বলে না',
                "গুদামে (পণ্য #{$productId})",
                $onHand,
                'FIFO স্তরে অবশিষ্ট',
                $layerQty,
                'মাল বেরিয়েছে কিন্তু স্তর টানা হয়নি (বা উল্টো) — খরচের দর তখন অনুমান হয়ে যায়।',
            ));
        }

        // ⭐ গোটা কোম্পানির দাবি — ব্যালান্স শিটের মজুদ = মজুদ রিপোর্টের মূল্য
        $onBooks = $this->fiveMatchMovementOf(StandardChart::INVENTORY);
        $inLayers = $this->fiveMatchLayerValueOf(null);

        $this->assertSame(0, bccomp($onBooks, $inLayers, 4), $this->fiveMatchMessage(
            'মিল ২ · মজুদ — খাত আর স্তর',
            'মজুদ খাতের জের (কোড '.StandardChart::INVENTORY.')',
            $onBooks,
            'সব FIFO স্তরের মোট মূল্য',
            $inLayers,
        ));
    }

    // ── ৩ · পক্ষের বকেয়া ────────────────────────────────────────────────

    /**
     * মিল ৩ — একজন পক্ষের খতিয়ান = পাওনা বা দেনা খাতের তার অংশ।
     *
     * ⓘ প্রশ্নটা আজ **প্রকাশযোগ্য**, আর সেটা মাপা হয়েছে:
     * `ledger_entries`-এ `party_type` ও `party_id` দুইটা কলামই আছে
     * (৪ আগস্টের মাইগ্রেশন), আর বিক্রয় `'customer'` বসায়
     * ([[SalesInvoiceService:671]], [[DirectSaleService:754]]) ও ক্রয়
     * `'supplier'` ([[PurchaseBillService:1175]])। তাই কাগজ ধরে ধরে
     * খুঁজতে হয় না।
     *
     * দুইটা দাবি একসাথে:
     *   ক · এই পক্ষের অংশ = হাতে গোনা অঙ্ক
     *   খ · খাতে বেনামি কোনো সারি নেই ([[assertEveryEntryNamesItsParty()]])
     *
     * ⚠️ দাবি (খ) ছাড়া একটা পক্ষের সংখ্যা ঠিক থাকলেও খাতে একটা
     * বেনামি সারি বসে থাকতে পারত, আর বকেয়ার তালিকার যোগফল কখনো
     * ব্যালান্স শিটের সাথে মিলত না।
     *
     * ── ⛔ দাবি (খ) আগে যা লেখা ছিল, আর কেন সেটা অকেজো ────────────────
     * প্রথমে লেখা হয়েছিল *"পক্ষ ধরে ধরে যোগ = গোটা খাত"*। ⚠️ ওটা
     * **কখনো লাল হতে পারত না**: `GROUP BY` গোটা খাতটাকেই ভাগ করে, তাই
     * ভাগগুলোর যোগফল মূলটার সমান হতে **বাধ্য** — গণিতের পরিচিতি, কোনো
     * দাবি নয়। ⓘ যে পাহারা কখনো লাল হয় না সে কিছুই পাহারা দেয় না,
     * কেবল সবুজ রং যোগ করে।
     */
    protected function assertThePartyOutstandingMatches(
        string $partyType,
        int $partyId,
        string $accountCode,
        string $expected,
    ): void {
        $accounts = $this->fiveMatchAccountFamily($accountCode);

        $share = $this->fiveMatchLedgerMovement($accounts, function ($query) use ($partyType, $partyId) {
            $query->where('party_type', $partyType)->where('party_id', $partyId);
        });

        $this->assertSame(0, bccomp($this->fiveMatchMoney($expected), $share, 4), $this->fiveMatchMessage(
            'মিল ৩ · পক্ষের বকেয়া',
            "হাতে গোনা ({$partyType}#{$partyId}, খাত {$accountCode} ".$this->fiveMatchAccountName($accountCode).')',
            $this->fiveMatchMoney($expected),
            'খতিয়ানে ঐ পক্ষের অংশ',
            $share,
        ));

        $this->assertEveryEntryNamesItsParty($accountCode);
    }

    /**
     * পাওনা বা দেনা খাতের প্রতিটা সারি কার, সেটা লেখা আছে।
     *
     * ⭐ এটাই সেই দাবি যা পক্ষের খতিয়ানকে ব্যালান্স শিটের সাথে বাঁধে:
     * বেনামি সারি না থাকলে পক্ষগুলোর যোগফল আর খাতের জের এক হতে বাধ্য।
     * ⚠️ একটা বেনামি সারি বসলে বকেয়ার তালিকা **কম** দেখাত, আর কম
     * দেখানো পাওনা মানে আদায় না-করা টাকা।
     *
     * ⓘ দাবিটা লাল হতে পারে, আর সেটাই মূল কথা: `party_type` বা
     * `party_id` মুছে দিলে এই অঙ্কটা সাথে সাথে শূন্য ছাড়িয়ে যায়।
     */
    protected function assertEveryEntryNamesItsParty(string $accountCode): void
    {
        $accounts = $this->fiveMatchAccountFamily($accountCode);

        $nameless = $this->fiveMatchLedgerMovement(
            $accounts,
            fn ($query) => $query->where(
                fn ($inner) => $inner->whereNull('party_type')->orWhereNull('party_id'),
            ),
        );

        $rows = DB::table('ledger_entries')
            ->where('company_id', $this->fiveMatchCompanyId())
            ->whereIn('account_id', $accounts)
            ->where(fn ($inner) => $inner->whereNull('party_type')->orWhereNull('party_id'))
            ->count();

        $this->assertSame(0, bccomp('0', $nameless, 4), $this->fiveMatchMessage(
            'মিল ৩ · পক্ষের বকেয়া — বেনামি সারি',
            "খাত {$accountCode} ".$this->fiveMatchAccountName($accountCode).'-এ পক্ষহীন জের থাকা উচিত',
            '0.0000',
            'আসলে পড়ে আছে',
            $nameless,
            "{$rows}টা সারিতে party_type বা party_id নেই — ওই টাকা কারও খতিয়ানে কখনো দেখাবে না।",
        ));
    }

    // ── ৪ · নগদ ও ব্যাংক ────────────────────────────────────────────────

    /**
     * মিল ৪ — নগদ বাক্স, ব্যাংক আর বিকাশের জের = হাতে গোনা টাকা।
     *
     * চাবিগুলো [[Account::MONEY_KINDS]]: `cash`, `bank`, `mfs`।
     *
     * ── ⓘ কোন খাতগুলো "টাকার খাত" ────────────────────────────────────
     * দুইভাবে ধরা হয়, আর ইউনিয়নটা ইচ্ছাকৃত:
     *   ক · `money_kind` কলামে ঐ ধরন লেখা আছে
     *   খ · `StandardChart`-এর মাথা থেকে নেমে আসা বংশ
     *       (`1101` নগদ, `1102` ব্যাংক, `1105` বিকাশ)
     *
     * ⚠️ কেবল (ক) ধরলে `money_kind` বসাতে ভুলে যাওয়া একটা ব্যাংক খাত
     * নীরবে হিসাবের বাইরে চলে যেত — আর সেটা ঠিক ওই ধরনের ফাঁক যা
     * "দুই পতাকার আসল ফাঁক" নামে একবার ধরা পড়েছে। ⚠️ কেবল (খ) ধরলে
     * মাথার বাইরে বসানো টাকার খাত বাদ পড়ত।
     *
     * @param  array<string, string>  $expected  ধরন => হাতে গোনা টাকা
     */
    protected function assertTheMoneyMatches(array $expected): void
    {
        $roots = [
            Account::CASH => StandardChart::CASH_IN_HAND,
            Account::BANK => StandardChart::BANK,
            Account::MFS => StandardChart::MOBILE_MONEY,
        ];

        foreach ($expected as $kind => $amount) {
            if (! isset($roots[$kind])) {
                throw new RuntimeException("টাকার অচেনা ধরন '{$kind}' — চলে: ".implode(', ', array_keys($roots)));
            }

            $accounts = $this->fiveMatchMoneyAccounts((string) $kind, $roots[$kind]);
            $actual = $this->fiveMatchLedgerMovement($accounts);

            $this->assertSame(0, bccomp($this->fiveMatchMoney($amount), $actual, 4), $this->fiveMatchMessage(
                'মিল ৪ · নগদ ও ব্যাংক',
                "হাতে গোনা ({$kind}, মাথা {$roots[$kind]})",
                $this->fiveMatchMoney($amount),
                'খতিয়ানে জের',
                $actual,
                count($accounts).'টা খাত গোনা হয়েছে (মাথা ও তার সব সন্তান)।',
            ));
        }
    }

    // ── ৫ · লাভ ─────────────────────────────────────────────────────────

    /**
     * মিল ৫ — (বিক্রয় − বিক্রয় ফেরত) − বিক্রীত পণ্যের খরচ = মোট লাভ।
     *
     * ⓘ আয়ের খাত ক্রেডিটে বসে, তাই আয়টা `ক্রেডিট − ডেবিট`; খরচ
     * ডেবিটে, তাই খরচটা `ডেবিট − ক্রেডিট`। ⚠️ দুইটাকে একই সূত্রে
     * টানলে লাভের চিহ্নটাই উল্টে যেত, আর একটা লোকসান লাভ হয়ে দেখাত।
     *
     * ⓘ বিক্রয় ফেরত (`4110`) আলাদা করে বাদ যায়, কারণ ছকে সে `4100`-এর
     * সন্তান নয়, ভাই — দুইজনের বাবা `4000`।
     */
    protected function assertTheGrossProfitMatches(string $expected): void
    {
        $sales = bcmul($this->fiveMatchMovementOf(StandardChart::SALES), '-1', 4);
        $returns = bcmul($this->fiveMatchMovementOf(StandardChart::SALES_RETURN), '-1', 4);
        $cost = $this->fiveMatchMovementOf(StandardChart::COST_OF_GOODS_SOLD);

        $profit = bcsub(bcadd($sales, $returns, 4), $cost, 4);

        $this->assertSame(0, bccomp($this->fiveMatchMoney($expected), $profit, 4), $this->fiveMatchMessage(
            'মিল ৫ · লাভ',
            'হাতে গোনা মোট লাভ',
            $this->fiveMatchMoney($expected),
            'খতিয়ান থেকে',
            $profit,
            'বিক্রয় '.$sales.' + বিক্রয় ফেরত '.$returns.' − বিক্রীত পণ্যের খরচ '.$cost,
        ));
    }

    // ── কাঁচা কোয়েরিগুলো ────────────────────────────────────────────────

    /**
     * কোন কোম্পানির হিসাব — প্রসঙ্গ না থাকলে থেমে যাওয়া।
     *
     * ⛔ প্রসঙ্গ ছাড়া চললে কোয়েরিগুলো `company_id` ছাড়া চলত, আর তখন
     * দুইটা কোম্পানির টাকা একসাথে গোনা হত — সবুজ, আর সম্পূর্ণ অর্থহীন।
     */
    protected function fiveMatchCompanyId(): int
    {
        $id = CompanyContext::id();

        if ($id === null) {
            throw new RuntimeException(
                'পাঁচ মিল মাপার আগে CompanyContext::set() করতে হবে — কার হিসাব, সেটা না জেনে কিছুই গোনা যায় না।'
            );
        }

        return $id;
    }

    /** খাত-কোড ধরে খাতটা আর তার সব সন্তানের চিহ্নসহ নড়াচড়া (ডেবিট − ক্রেডিট)। */
    protected function fiveMatchMovementOf(string $code): string
    {
        return $this->fiveMatchLedgerMovement($this->fiveMatchAccountFamily($code));
    }

    /**
     * কোড ধরে একটা খাত — মুছে ফেলা খাত বাদ, আর না পেলে থেমে যাওয়া।
     *
     * ⓘ `deleted_at` দেখা হয় হাতে, কারণ এখানে Eloquent ব্যবহার করা
     * হচ্ছে না — soft delete-এর নিয়মটা মডেলে থাকে, কাঁচা কোয়েরিতে নয়।
     *
     * @return array<string, mixed>
     */
    protected function fiveMatchAccountRow(string $code): array
    {
        $row = DB::table('accounts')
            ->where('company_id', $this->fiveMatchCompanyId())
            ->where('code', $code)
            ->whereNull('deleted_at')
            ->first(['id', 'code', 'name_bn', 'name_en']);

        if ($row === null) {
            throw new RuntimeException(
                "হিসাবের ছকে '{$code}' খাতটা নেই — StandardChart::install() চলেছে কি না দেখতে হবে।"
            );
        }

        return (array) $row;
    }

    protected function fiveMatchAccountName(string $code): string
    {
        $row = $this->fiveMatchAccountRow($code);

        return (string) ($row['name_bn'] ?? $row['name_en'] ?? '');
    }

    /**
     * একটা খাত আর তার সব বংশধরের আইডি।
     *
     * ⓘ ছকটা গভীর নয় (তিন-চার স্তর), তাই স্তরে স্তরে নামা হয় — recursive
     * CTE লিখলে MariaDB আর MySQL দুইটাতেই আলাদা করে মেপে দেখতে হত,
     * আর লাইভ MariaDB, দেব MySQL।
     *
     * @return list<int>
     */
    protected function fiveMatchAccountFamily(string $code): array
    {
        $ids = [(int) $this->fiveMatchAccountRow($code)['id']];
        $frontier = $ids;

        while ($frontier !== []) {
            $children = DB::table('accounts')
                ->where('company_id', $this->fiveMatchCompanyId())
                ->whereIn('parent_id', $frontier)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $children = array_values(array_diff($children, $ids));

            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }

    /**
     * টাকার খাতগুলো — `money_kind` আর বংশ, দুইটার ইউনিয়ন।
     *
     * @return list<int>
     */
    protected function fiveMatchMoneyAccounts(string $kind, string $rootCode): array
    {
        $byKind = DB::table('accounts')
            ->where('company_id', $this->fiveMatchCompanyId())
            ->where('money_kind', $kind)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($byKind, $this->fiveMatchAccountFamily($rootCode))));
    }

    /**
     * একগুচ্ছ খাতের চিহ্নসহ নড়াচড়া — ডেবিট − ক্রেডিট, bcmath-এ।
     *
     * ⛔ `->sum('debit')` নয়: সে float বানায় (`MoneyIsNeverAFloatTest`)।
     * ⓘ দুইটা যোগফল একই কোয়েরিতে আসে, তাই টেবিলটা একবারই পড়া হয়।
     *
     * @param  list<int>  $accountIds
     * @param  callable(\Illuminate\Database\Query\Builder): void|null  $narrow
     */
    protected function fiveMatchLedgerMovement(array $accountIds, ?callable $narrow = null): string
    {
        if ($accountIds === []) {
            return '0.0000';
        }

        $query = DB::table('ledger_entries')
            ->where('company_id', $this->fiveMatchCompanyId())
            ->whereIn('account_id', $accountIds);

        if ($narrow !== null) {
            $narrow($query);
        }

        $row = $query->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();

        return bcsub($this->fiveMatchMoney($row->d ?? '0'), $this->fiveMatchMoney($row->c ?? '0'), 4);
    }

    /**
     * গুদামে মোট কত — তাকে যা আছে, আর গাড়ি থেকে নেমে যা বসে আছে।
     *
     * ⓘ `reserved_change` আর `hold_change` যোগ হয় না: ওরা তাকের মালেরই
     * অংশ, আলাদা মাল নয় ([[StockService::statesFor()]]-এর `available`
     * সূত্র)। ⚠️ যোগ করলে একই বস্তা দুইবার গোনা হত।
     */
    protected function fiveMatchStockOnHand(int $productId, ?int $warehouseId = null): string
    {
        return $this->fiveMatchStockSum($productId, 'floor_change + unplaced_change', $warehouseId);
    }

    /** ফ্রি ভাণ্ডারে মোট কত — বিক্রির মজুদের বাইরে, আর তার কোনো স্তর নেই। */
    protected function fiveMatchFreeOnHand(int $productId, ?int $warehouseId = null): string
    {
        return $this->fiveMatchStockSum($productId, 'free_change + unplaced_free_change', $warehouseId);
    }

    private function fiveMatchStockSum(int $productId, string $columns, ?int $warehouseId): string
    {
        $query = DB::table('inv_stock_movements')
            ->where('company_id', $this->fiveMatchCompanyId())
            ->where('product_id', $productId);

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        $row = $query->selectRaw("COALESCE(SUM({$columns}), 0) as qty")->first();

        return $this->fiveMatchMoney($row->qty ?? '0');
    }

    /** FIFO স্তরে অবশিষ্ট পরিমাণ — একটা পণ্যের, বা null দিলে গোটা কোম্পানির। */
    protected function fiveMatchLayerQtyOf(?int $productId): string
    {
        return $this->fiveMatchLayerSum($productId, 'qty_remaining');
    }

    /** FIFO স্তরে অবশিষ্ট মালের দাম — এটাই মজুদের আসল মূল্য। */
    protected function fiveMatchLayerValueOf(?int $productId): string
    {
        return $this->fiveMatchLayerSum($productId, 'qty_remaining * unit_cost');
    }

    private function fiveMatchLayerSum(?int $productId, string $expression): string
    {
        $query = DB::table('inv_cost_layers')->where('company_id', $this->fiveMatchCompanyId());

        if ($productId !== null) {
            $query->where('product_id', $productId);
        }

        $row = $query->selectRaw("COALESCE(SUM({$expression}), 0) as total")->first();

        return $this->fiveMatchMoney($row->total ?? '0');
    }

    /**
     * FIFO-তে যা টানা হয়েছে — একটা কাগজের খরচ, স্তরের টানের সারি থেকে।
     *
     * ⓘ মিল ৫ এটা ব্যবহার করে না (সে খতিয়ানের `5100` পড়ে), কিন্তু
     * প্রমাণ-পরীক্ষাগুলোর দরকার হয়: খাতায় বসা খরচ আর স্তর থেকে টানা
     * খরচ এক কি না, সেটাই FIFO-র নিজের মিল।
     */
    protected function fiveMatchLayerUseOf(string $sourceType, int $sourceId): string
    {
        $row = DB::table('inv_cost_layer_uses')
            ->where('company_id', $this->fiveMatchCompanyId())
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->first();

        return $this->fiveMatchMoney($row->total ?? '0');
    }

    /**
     * যেকোনো সংখ্যাকে scale ৪-এর bcmath স্ট্রিং করা।
     *
     * ⛔ `(float)` নয় — একবার float হলে ৪ দশমিকের পরে যা হারায় তা আর
     * ফেরে না, আর টাকার হিসাবে ওই হারানোটাই "গরমিল"।
     */
    protected function fiveMatchMoney(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }

        return bcadd((string) $value, '0', 4);
    }

    /**
     * ব্যর্থতার বার্তা — কোন অঙ্ক, আর দুই পাশে কত।
     *
     * ⛔ "মেলে না" জাতীয় বার্তা বসানো হয় না: পড়ে কেউ বুঝতে পারত না
     * কোন সংখ্যাটা ভুল, আর তখন প্রতিবার হাতে কোয়েরি লিখে খুঁজতে হত।
     */
    protected function fiveMatchMessage(
        string $which,
        string $expectedLabel,
        string $expected,
        string $actualLabel,
        string $actual,
        string $note = '',
    ): string {
        $lines = [
            $which.' — মেলেনি।',
            "  {$expectedLabel}: {$expected}",
            "  {$actualLabel}: {$actual}",
            '  ফারাক: '.bcsub($expected, $actual, 4),
        ];

        if ($note !== '') {
            $lines[] = '  '.$note;
        }

        return implode(PHP_EOL, $lines);
    }
}
