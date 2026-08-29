<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Posting\PostingException;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use Illuminate\Support\Carbon;

/**
 * পুরনো হিসাব থেকে নিয়ে আসা ব্যালেন্স খাতায় বসানো।
 *
 * এটা না থাকলে যা হত — আর প্রথমে হয়েছিলও — গ্রাহক ও সরবরাহকারীর খোলা
 * ব্যালেন্স শুধু তাদের নিজের সারিতে বসে থাকত, লেজারে কিছুই যেত না। ফলে
 * একজনের পাতায় "প্রদেয় ১,২৫,০০০" দেখাত, অথচ প্রদেয় তালিকায় তার নামই
 * থাকত না — কারণ ওই রিপোর্ট লেজার থেকে গোনে। দুইটা সংখ্যা দুই জায়গা
 * থেকে এলে একদিন আলাদা হবেই, আর তখন কোনটা সত্যি তা বলার উপায় থাকে না।
 *
 * এখন খোলা ব্যালেন্সও একটা দাখিলা: পক্ষের খাত বনাম সঞ্চিত মুনাফা।
 * সঞ্চিত মুনাফাই ঠিক প্রতিপক্ষ, কারণ পুরনো বছরের ফল ওখানেই জমা —
 * নতুন খাতায় ঢোকার সময় সম্পদ ও দায়ের পার্থক্যটা ওটাই ধরে রাখে।
 *
 * দুই পক্ষের জন্য দুইটা পদ্ধতি, একটা সাধারণ post() নয়: চিহ্ন উল্টো,
 * আর "কোন দিকে ডেবিট" প্রশ্নটা ডাকার জায়গায় ছেড়ে দিলে একদিন কেউ
 * উল্টো দিকে বসাত — VoucherService::twoLineEntry()-তেও একই কারণে
 * সিদ্ধান্তটা একবারই নেওয়া হয়েছে।
 */
final class OpeningBalanceService
{
    /** এই দাখিলাগুলোর নিজস্ব ধরন — ড্রিল-ডাউনে পক্ষের পাতাতেই ফেরে। */
    public const SOURCE_SUFFIX = ':opening';

    /**
     * ছকের খাতের নিজের জের — পক্ষ নয়, তাই নিজের একটা "ধরন"।
     *
     * পক্ষের ধরনগুলো (`customer`, `supplier`) আসে ডাকার জায়গা থেকে;
     * এখানে পক্ষ বলে কিছু নেই, খাতটাই বিষয়। নামটা ধ্রুবক, কারণ
     * পুরনো সারি খুঁজতে [[PostMissingOpenings]]-ও একই নাম ব্যবহার করে।
     */
    public const ACCOUNT_SOURCE = 'account';

    public function __construct(private readonly PostingEngine $posting) {}

    /**
     * গ্রাহকের কাছে আমাদের পাওনা — প্রাপ্য ডেবিট।
     *
     * @return list<LedgerEntry>
     */
    public function forReceivable(
        string $partyType,
        int $partyId,
        string $documentNo,
        string $amount,
        Carbon|string|null $date = null,
    ): array {
        return $this->post(
            $partyType, $partyId, $documentNo, $amount, $date,
            partyAccount: StandardChart::RECEIVABLE,
            partyIsDebit: true,
        );
    }

    /**
     * সরবরাহকারীকে আমাদের দেনা — প্রদেয় ক্রেডিট।
     *
     * @return list<LedgerEntry>
     */
    public function forPayable(
        string $partyType,
        int $partyId,
        string $documentNo,
        string $amount,
        Carbon|string|null $date = null,
    ): array {
        return $this->post(
            $partyType, $partyId, $documentNo, $amount, $date,
            partyAccount: StandardChart::PAYABLE,
            partyIsDebit: false,
        );
    }

    /**
     * শুরুর দিন তাকে যা ছিল — মজুদ ডেবিট, অবশিষ্ট মুনাফা ক্রেডিট।
     *
     * ── কেন এটা লাগল ────────────────────────────────────────────────
     * খোলা মজুদ এতদিন কেবল গুদামে ঢুকত (পরিমাণ) আর স্তরে বসত (দাম),
     * কিন্তু খতিয়ানে কিছুই যেত না। ফল: ডিপোর তাকে ৮,৪০,০০০ টাকার মাল,
     * অথচ ব্যালেন্স শিটে মজুদ শূন্য। সম্পদটা ছিল, খাতায় ছিল না।
     *
     * ধরা পড়েছে FIFO বসানোর পর — স্তরে মালের মূল্য আর খতিয়ানের মজুদ
     * পাশাপাশি রেখে। আগে দুইটা সংখ্যা কখনো একসাথে দেখা হত না, তাই
     * ফাঁকটাও কেউ দেখেনি।
     *
     * ── কেন অবশিষ্ট মুনাফার বিপরীতে, ঘাটতি-উদ্বৃত্ত খাতে নয় ─────────
     * শুরুর দিনের মাল এই বছরের কোনো ঘটনা নয় — সেটা আগের ব্যবসার ফল,
     * নতুন খাতায় তোলা হচ্ছে মাত্র। ঘাটতি-উদ্বৃত্ত খাতে ফেললে প্রথম
     * মাসেই আট লাখ টাকা "আয়" দেখাত, অথচ কেউ কিছু বেচেনি।
     *
     * গ্রাহক ও সরবরাহকারীর খোলা ব্যালেন্সেও একই খাত, একই কারণে।
     *
     * ── sourceId পণ্যের নয়, চলাচলের ──────────────────────────────────
     * প্রথমে পণ্যের id দেওয়া হয়েছিল, আর তাতে একই পণ্য দুই গুদামে থাকলে
     * দ্বিতীয় দাখিলাটা বাতিল হয়ে যেত — পোস্টিং ইঞ্জিন একই উৎসে দুইবার
     * বসতে দেয় না, আর সেটা ঠিকই করে (নইলে একই কাগজ দুইবার পোস্ট করলে
     * খাতা দ্বিগুণ হত)।
     *
     * ধরা পড়েছে সিডার চালিয়ে: নেত্রকোনার ৪০ বস্তা চাল, ১,৩৬,০০০ টাকা,
     * নীরবে বাদ পড়ে গিয়েছিল। চলাচলের id দিলে প্রতিটা গুদামের প্রতিটা
     * ঢোকা আলাদা ঘটনা — যা সত্যিও।
     *
     * @return list<LedgerEntry>
     */
    public function forInventory(
        int $sourceId,
        string $documentNo,
        string $amount,
        Carbon|string|null $date = null,
    ): array {
        if (bccomp($amount, '0', 4) <= 0) {
            return [];
        }

        $inventory = StandardChart::find(StandardChart::INVENTORY);
        $equity = StandardChart::find(StandardChart::RETAINED_EARNINGS);

        if ($inventory === null || $equity === null) {
            throw new PostingException(
                'Opening stock needs the standard chart — install it before bringing stock in.'
            );
        }

        $narration = $this->narration();

        return $this->posting->post(
            sourceType: 'opening_stock',
            sourceId: $sourceId,
            trxDate: $this->dateFor($date),
            lines: [
                ['account_id' => $inventory->id, 'debit' => $amount, 'narration' => $narration],
                ['account_id' => $equity->id, 'credit' => $amount, 'narration' => $narration],
            ],
            documentNo: $documentNo,
        );
    }

    /**
     * ছকের একটা খাতের নিজের খোলা ব্যালেন্স — খাতায়, কেবল কলামে নয়।
     *
     * ── কী ভাঙা ছিল, ২৯ আগস্ট ২০২৬ ─────────────────────────────────
     * গ্রাহক, সরবরাহকারী আর মজুদ — তিনটার খোলা ব্যালেন্সই এই সেবার
     * মধ্য দিয়ে খাতায় যেত। **হিসাবের ছকের নিজের খাতগুলো যেত না।**
     * ওদের জেরটা `accounts.opening_balance` কলামে বসে থাকত, আর
     * `Account::balanceOn()` সেটা কোডে যোগ করত।
     *
     * ফল, HP-র রিপোর্ট থেকে হুবহু: ব্যাংকের পাতা ৮০,০০০ দেখাত,
     * ট্রায়াল ব্যালেন্স ও স্থিতিপত্র ৩০,০০০। এই ফাইলের মাথায় লেখা
     * রোগটাই — "দুইটা সংখ্যা দুই জায়গা থেকে এলে একদিন আলাদা হবেই" —
     * কেবল অন্য একটা দরজা দিয়ে ফিরে এসেছিল।
     *
     * ── কেন এখানে, নতুন কোনো সেবায় নয় ──────────────────────────────
     * প্রথমে আলাদা একটা সেবা লিখতে গিয়েছিলাম, আর তাতে বিপরীত দিকটা
     * একটা নতুন "খোলার জের" খাতে যেত। সেটা ভুল হত: তখন একই ঘটনা দুই
     * ইকুইটি খাতে ভাগ হয়ে যেত — পক্ষের জের ৩৩০০-এ, খাতের জের ৩৪০০-এ।
     * এই ফাইলটাই আগে থেকে বলে দিয়েছে কেন ৩৩০০-ই ঠিক প্রতিপক্ষ।
     *
     * @return list<LedgerEntry>
     */
    public function forAccount(Account $account): array
    {
        $amount = (string) $account->opening_balance;

        if (bccomp($amount, '0', 4) === 0 || $account->is_group) {
            return [];
        }

        if ($this->exists(self::ACCOUNT_SOURCE, $account->id)) {
            return [];
        }

        $equity = StandardChart::find(StandardChart::RETAINED_EARNINGS);

        if ($equity === null) {
            throw new PostingException(
                'Opening balances need the standard chart — install it before opening accounts with a balance.'
            );
        }

        /*
         * চিহ্নটা খাতের স্বাভাবিক দিক থেকে, ডাকার জায়গা থেকে নয়।
         *
         * কলামে সংখ্যাটা সবসময় ধনাত্মক আর স্বাভাবিক দিকে — সম্পদে
         * ডেবিট, দায়ে ক্রেডিট। সিদ্ধান্তটা এখানেই একবার, ঠিক যে
         * কারণে `forReceivable`/`forPayable` দুইটা আলাদা পদ্ধতি।
         */
        $accountIsDebit = $account->nature !== Account::CREDIT;

        $narration = $this->narration();

        return $this->posting->post(
            sourceType: self::ACCOUNT_SOURCE.self::SOURCE_SUFFIX,
            sourceId: $account->id,
            trxDate: $this->dateFor($account->opening_date),
            lines: [
                [
                    'account_id' => $account->id,
                    'narration' => $narration,
                    $accountIsDebit ? 'debit' : 'credit' => $amount,
                ],
                [
                    'account_id' => $equity->id,
                    'narration' => $narration,
                    $accountIsDebit ? 'credit' : 'debit' => $amount,
                ],
            ],
            documentNo: $account->code,
        );
    }

    /**
     * খোলা ব্যালেন্সের দাখিলা আছে কি না।
     *
     * সম্পাদনায় খোলা ব্যালেন্স বদলানো যায় না, তবু এটা দরকার: পুরনো
     * ডাটা ঠিক করার সময় জানতে হয় কার দাখিলা ইতিমধ্যেই বসেছে, নাহলে
     * দ্বিতীয়বার চালালে সংখ্যাটা দ্বিগুণ হত।
     */
    public function exists(string $partyType, int $partyId): bool
    {
        return LedgerEntry::query()
            ->where('source_type', $partyType.self::SOURCE_SUFFIX)
            ->where('source_id', $partyId)
            ->exists();
    }

    /**
     * @return list<LedgerEntry>
     */
    private function post(
        string $partyType,
        int $partyId,
        string $documentNo,
        string $amount,
        Carbon|string|null $date,
        string $partyAccount,
        bool $partyIsDebit,
    ): array {
        // শূন্য খোলা ব্যালেন্স মানে কিছুই আনার নেই — দাখিলা বসালে
        // লেজারে দুইটা শূন্য সারি থাকত, যা পড়ার সময় শুধু বিভ্রান্ত করে
        if (bccomp($amount, '0', 4) === 0) {
            return [];
        }

        /*
         * ঋণাত্মক খোলা ব্যালেন্স বাস্তব: সরবরাহকারীকে আগাম দেওয়া আছে,
         * বা গ্রাহক বেশি দিয়ে ফেলেছে। তখন দুই দিক উল্টে যায়, আর অঙ্কটা
         * ধনাত্মক করে বসাতে হয় — লেজারে ঋণাত্মক ডেবিট বলে কিছু নেই।
         */
        if (bccomp($amount, '0', 4) < 0) {
            $partyIsDebit = ! $partyIsDebit;
            $amount = bcmul($amount, '-1', 4);
        }

        $party = StandardChart::find($partyAccount);
        $equity = StandardChart::find(StandardChart::RETAINED_EARNINGS);

        if ($party === null || $equity === null) {
            throw new PostingException(
                'Opening balances need the standard chart — install it before adding parties with a balance.'
            );
        }

        $narration = $this->narration();

        $partyLine = [
            'account_id' => $party->id,
            'party_type' => $partyType,
            'party_id' => $partyId,
            'narration' => $narration,
            $partyIsDebit ? 'debit' : 'credit' => $amount,
        ];

        $equityLine = [
            'account_id' => $equity->id,
            'narration' => $narration,
            $partyIsDebit ? 'credit' : 'debit' => $amount,
        ];

        return $this->posting->post(
            sourceType: $partyType.self::SOURCE_SUFFIX,
            sourceId: $partyId,
            trxDate: $this->dateFor($date),
            lines: [$partyLine, $equityLine],
            documentNo: $documentNo,
        );
    }

    /**
     * সারির বিবরণ — কোম্পানির ভাষায়, ব্যবহারকারীর নয়।
     *
     * এটাই একমাত্র জায়গা যেখানে সিস্টেম নিজে লেজারের narration লেখে,
     * আর লেখাটা সারিতে জমা থাকে। ব্যবহারকারীর ভাষা ধরলে ইংরেজিতে
     * কাজ করা হিসাবরক্ষকের বসানো সারি বাংলায় চলা প্রতিষ্ঠানের খাতায়
     * ইংরেজিতে থেকে যেত — একই খাতায় দুই ভাষা।
     */
    private function narration(): string
    {
        $locale = Company::query()->whereKey(CompanyContext::id())->value('locale');

        return __('accounts::message.opening_balance', [], $locale ?? config('app.locale'));
    }

    /**
     * তারিখ না বললে চলতি অর্থবছরের প্রথম দিন।
     *
     * আজকের তারিখ নয়: খোলা ব্যালেন্স বছরের শুরুর অবস্থা, আর মাঝপথে
     * বসালে ওই তারিখের আগের যেকোনো রিপোর্টে সংখ্যাটা উধাও হয়ে যেত।
     */
    private function dateFor(Carbon|string|null $date): Carbon
    {
        $year = FinancialYear::query()->where('is_current', true)->first();

        if ($year === null) {
            throw new PostingException(
                'Opening balances need a current financial year — none is set for this company.'
            );
        }

        $start = Carbon::parse($year->starts_on);

        if ($date === null) {
            return $start;
        }

        $given = $date instanceof Carbon ? $date : Carbon::parse($date);

        /*
         * অর্থবছরের আগের তারিখ হলে বছরের প্রথম দিনে বসে।
         *
         * পুরনো খাতা থেকে আনার সময় এটা প্রায় সবসময় ঘটে: করিমের কাছে
         * বকেয়াটা ২০২৪ সাল থেকে, অথচ ABOS-এ প্রথম অর্থবছর ২০২৬-২৭।
         * ওই তারিখে কোনো অর্থবছর নেই, তাই Posting engine ঠিকই ব্যতিক্রম
         * ছুঁড়ত — আর পুরো ইমপোর্টটা আটকে যেত।
         *
         * সরিয়ে দেওয়াটা শুধু কারিগরি সুবিধা নয়, হিসাবেও ঠিক: খাতা যেদিন
         * শুরু হয়েছে তার আগের কোনো দিনে দাখিলা বসানোর মানে হয় না। আসল
         * তারিখটা হারায় না — সেটা পক্ষের নিজের opening_date ঘরে থেকে যায়।
         */
        return $given->lt($start) ? $start : $given;
    }
}
