<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Posting\PostingException;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Events\OpeningCapitalBooked;
use App\Modules\Accounts\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    /**
     * ⭐ খোলা জেরের নিজের নম্বর-সিরিজ — OB-0001 (ভাউচারের আন্তর্জাতিক পরিকল্পনা, অংশ ৩ঘ, ৭ অক্টোবর ২০২৬)।
     *
     * ⓘ প্রতিটা খোলা দাখিলা (পক্ষ, ছকের খাত, মজুদ, মজুদের সংশোধন) একটা OB নম্বর পায়, ফাঁক ছাড়া — খাতা আর রিপোর্টে
     * খোলা জের আলাদা চেনা যায়। ⛔ আগে নম্বর ছিল ডাকার জায়গার: পক্ষের কোড, খাতের কোড, মজুদে "OPENING"। আজকের বসানো
     * দাখিলা যেমন আছে থাকে।
     */
    public const SERIES = 'OB';

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
        ?int $branchId = null,
    ): array {
        return $this->post(
            $partyType, $partyId, $documentNo, $amount, $date,
            partyAccount: StandardChart::RECEIVABLE,
            partyIsDebit: true,
            branchId: $branchId,
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
        ?int $branchId = null,
        // ⓘ কোন দেনার ঘরে — পরিবহনকারী ২১১৬, হাম্মালি ঠিকাদার ২১১৭; বাকিরা ২১১১ ([[SupplierService::openingAccountFor()]])
        string $account = StandardChart::PAYABLE,
    ): array {
        return $this->post(
            $partyType, $partyId, $documentNo, $amount, $date,
            partyAccount: $account,
            partyIsDebit: false,
            branchId: $branchId,
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

        /*
         * ⭐ কোন শাখার খাতায় — গুদামের শাখা, লেখকের নয় (Inventory অডিট ম১১, ৫ অক্টোবর ২০২৬)। ⛔ আগে দাখিলা বসত লেখকের
         * শাখায় বা কোনো শাখায় নয়, আর গুদামের শাখার স্থিতিপত্রে তার মজুদের টাকা থাকত না। `null` মানে আগের আচরণ।
         */
        ?int $branchId = null,
    ): array {
        if (bccomp($amount, '0', 4) <= 0) {
            return [];
        }

        // ⛔ খাত কোড ধরে, মানে ধরেই — না পেলে থামা, ভুল খাতে বা অর্ধেক জাবেদায় নয় ([[StandardChart::accountFor()]])
        $inventory = StandardChart::accountFor(StandardChart::INVENTORY);
        $equity = $this->openingEquity();

        $narration = $this->narration();

        return $this->announce($equity, $this->numbered(
            sourceType: 'opening_stock',
            sourceId: $sourceId,
            trxDate: $this->dateFor($date),
            lines: [
                ['account_id' => $inventory->id, 'debit' => $amount, 'narration' => $narration],
                ['account_id' => $equity->id, 'credit' => $amount, 'narration' => $narration],
            ],
            branchId: $branchId,
        ));
    }

    /**
     * ⭐ খোলা মজুদের এক সারির মূল্য ফেরানো — সংশোধন বা মুছে ফেলা (মালিক, ৬ অক্টোবর ২০২৬)।
     *
     * ⓘ কার্ট সব সারির জন্য **একটা** দাখিলা দেয়, তাই এক সারির জন্য পুরো দাখিলা উল্টানো যায় না ([[PostingEngine::reverse()]]
     * সবটা উল্টায়)। ⭐ তাই সারির নিজের মূল্যে আলাদা উল্টো দাখিলা — Dr শুরুর মূলধন / Cr মজুদ — নিজের উৎসে
     * (`opening_stock:withdrawn`, সংশোধনের চলাচলের id)। ⛔ পুরনো সারি মোছা বা বদলানো হয় না; খাতার শিকল অক্ষত।
     *
     * @return list<LedgerEntry>
     */
    public function withdrawInventory(
        int $sourceId,
        string $documentNo,
        string $amount,
        Carbon|string|null $date = null,
        ?int $branchId = null,
    ): array {
        if (bccomp($amount, '0', 4) <= 0) {
            return [];
        }

        // ⛔ খাত কোড ধরে, মানে ধরেই — না পেলে থামা, ভুল খাতে বা অর্ধেক জাবেদায় নয় ([[StandardChart::accountFor()]])
        $inventory = StandardChart::accountFor(StandardChart::INVENTORY);
        $equity = $this->openingEquity();

        $narration = $this->narration();

        return $this->numbered(
            sourceType: 'opening_stock:withdrawn',
            sourceId: $sourceId,
            trxDate: $this->dateFor($date),
            lines: [
                ['account_id' => $equity->id, 'debit' => $amount, 'narration' => $narration],
                ['account_id' => $inventory->id, 'credit' => $amount, 'narration' => $narration],
            ],
            branchId: $branchId,
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
    /** পক্ষ-রাখা খাত, মালিকের মূলধনের বংশ বাদে — খোলা জের কেবল পক্ষের নামে ([[forAccount()]], আমদানির যাচাইও এটাই ডাকে) */
    public static function needsAParty(Account $account): bool
    {
        if (! $account->holdsParty()) {
            return false;
        }

        $capital = StandardChart::find(StandardChart::OWNER_CAPITAL);

        return $capital === null || ! $capital->selfAndDescendants()->pluck('id')->contains($account->id);
    }

    public function forAccount(Account $account): array
    {
        $amount = (string) $account->opening_balance;

        if (bccomp($amount, '0', 4) === 0 || $account->is_group) {
            return [];
        }

        if ($this->exists(self::ACCOUNT_SOURCE, $account->id)) {
            return [];
        }

        /*
         * ⛔ পক্ষ-রাখা খাতে খাতের নিজের খোলা জের নয় — কেবল পক্ষের নামে (cb-র নিরীক্ষা, fe, ১৪ অক্টোবর ২০২৬;
         * [[AControlAccountOpensOnlyThroughItsPartiesTest]])। ⓘ পাওনা, দেনা বা হাতধারের খাতে নাম ছাড়া খোলা জের বসালে খাতের জের আর
         * পক্ষদের যোগ চিরকাল আলাদা — রাতের books-check রোজ "ভাঙা" বলত, আর কার পাওনা কেউ বলতে পারত না। গ্রাহক বা সরবরাহকারীর নিজের
         * খোলা জেরের পথ আছে ([[forReceivable()]], [[forPayable()]])। ⓘ মালিকের মূলধন (৩১০০) বাদ: সেখানে নাম বসাতে পারে, বাধ্য নয়।
         */
        if (self::needsAParty($account)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'opening_balance' => __('accounts::validation.opening_needs_party', ['name' => $account->name()]),
            ]);
        }

        $equity = $this->openingEquity();

        /*
         * চিহ্নটা খাতের স্বাভাবিক দিক থেকে, ডাকার জায়গা থেকে নয়।
         *
         * কলামে সংখ্যাটা সবসময় ধনাত্মক আর স্বাভাবিক দিকে — সম্পদে
         * ডেবিট, দায়ে ক্রেডিট। সিদ্ধান্তটা এখানেই একবার, ঠিক যে
         * কারণে `forReceivable`/`forPayable` দুইটা আলাদা পদ্ধতি।
         */
        $accountIsDebit = $account->nature !== Account::CREDIT;

        /*
         * ⛔ টাকার খাত কখনো Cr খোলা জের নেয় না — মালিক, ১৩ অক্টোবর ২০২৬ ("int standrad onuzayi koro"; IAS 32/IAS 7;
         * [[AnOpeningBalanceSitsOnTheSideChosenTest]])। ⓘ নগদ বা মোবাইল-ব্যাংকিং Cr হয় না — থামে। ব্যাংকের Cr মানে ওভারড্রাফট, একটা
         * দায়: সেটা সেই ব্যাংকের ওভারড্রাফটের খাতে বসে ([[AccountService::overdraftFor()]]), ব্যাংকের খাত শূন্যে খোলে।
         */
        $postsCredit = $accountIsDebit === (bccomp($amount, '0', 4) < 0);

        if ($account->money_kind !== null && $postsCredit) {
            if (! $account->isBank()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'opening_side' => __('accounts::validation.money_opens_on_dr_only', ['name' => $account->name()]),
                ]);
            }

            $overdraft = app(AccountService::class)->overdraftFor($account);
            $account->forceFill(['opening_balance' => 0])->save();
            $overdraft->forceFill([
                'opening_balance' => ltrim($amount, '-'),
                'opening_date' => $account->opening_date,
            ])->save();

            return $this->forAccount($overdraft);
        }

        // ⓘ খাতের কোড বিবরণে — খাতার নম্বর এখন OB সিরিজের ([[SERIES]])
        $narration = $this->narration().' · '.$account->code;

        return $this->announce($equity, $this->numbered(
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
        ));
    }

    /**
     * বিদ্যমান খাতে খোলার জের বসানো — কোড ধরে ইমপোর্টের জন্য।
     *
     * কেন আলাদা পদ্ধতি লাগল: create() খাত তৈরির সাথেই forAccount() ডাকে,
     * আর update() খোলা ব্যালেন্স বদলাতে দেয় না — দুইটাই ইচ্ছাকৃত। ফলে ছক
     * আগে তুলে পরে জের বসানোর, অর্থাৎ দুই ধাপের ইমপোর্টের, কোনো পথই ছিল না।
     * এটা সেই পথ, আর কেবল যোগ: forAccount-সহ পুরনো পাঁচটা পদ্ধতির আচরণ
     * অপরিবর্তিত।
     *
     * idempotent — exists() দিয়ে আগে-বসা জের দেখে থামে, খাতটা ছোঁয়ও না।
     * তাই আংশিক ইমপোর্ট আবার চালালে বাকিটা সম্পূর্ণ হয়, দ্বিগুণ নয়।
     *
     * @return list<LedgerEntry>
     */
    public function loadFor(Account $account, string $amount, Carbon|string|null $date = null): array
    {
        // ⛔ ঘরে লেখার আগেই — নইলে থামলেও খাতের ঘরে ভুল অঙ্ক থেকে যেত ([[needsAParty()]])
        if (self::needsAParty($account)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'opening_balance' => __('accounts::validation.opening_needs_party', ['name' => $account->name()]),
            ]);
        }

        if ($this->exists(self::ACCOUNT_SOURCE, $account->id)) {
            return [];
        }

        // opening_balance ও opening_date ঘরে থাকে (রেকর্ড হিসেবে), আর
        // পোস্টিংটা forAccount করে — খতিয়ানই সত্য, কলামটা তার প্রতিধ্বনি।
        $account->fill([
            'opening_balance' => $amount,
            'opening_date' => $date instanceof Carbon ? $date->toDateString() : $date,
        ])->save();

        return $this->forAccount($account);
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
    /**
     * ⭐ খোলা জের কোন মালিকানার খাতে — মালিক, ৫ অক্টোবর ২০২৬: *"Customer balance & Opening stock eguli ki Maliker capital
     * e zabena?"*। ⓘ একক মালিকের ব্যবসায় শুরুর দিনের পাওনা, মজুদ আর খাতের জের মালিকেরই পুঁজি — আন্তর্জাতিক চর্চাও
     * শেষে মূলধনে নেয়। ⛔ আগে সব [[StandardChart::RETAINED_EARNINGS]]-এ (৩৩০০) বসত, যেটা আগের বছরগুলোর জমা লাভের খাত।
     * ⓘ কোম্পানির সুইচ `accounts.opening_to_capital` (ডিফল্ট চালু): বন্ধ করলে আগের মতো সংরক্ষিত মুনাফায় — ABOS অনেক
     * ব্যবসায় বিক্রি হয়, আর অংশীদারি বা কোম্পানির হিসাবে সেটাই চায় কেউ কেউ। মূলধনের খাত না থাকলে সংরক্ষিত মুনাফায়।
     */
    /**
     * ⭐ খোলা জের মালিকের মূলধনে বসলে খবর — অর্থ মডিউল রেজিস্টারে মালিকের নামে তোলে (মালিকের আদেশ, ৫ অক্টোবর ২০২৬;
     * [[OpeningCapitalBooked]])। ⓘ হিসাব অর্থকে চেনে না — কেবল ঘটনা ছোড়ে, লেনদেন পাকা হওয়ার পরে।
     *
     * @template T
     *
     * @param  T  $rows
     * @return T
     */
    private function announce(?Account $equity, mixed $rows): mixed
    {
        if ($equity !== null && $equity->code === StandardChart::OWNER_CAPITAL) {
            $companyId = (int) CompanyContext::id();
            DB::afterCommit(fn () => event(new OpeningCapitalBooked(publicId: (string) $equity->public_id, payload: [], companyId: $companyId)));
        }

        return $rows;
    }

    // ⛔ খাত কোড ধরে, মানে ধরেই — না পেলে থামা, ভুল খাতে বা অর্ধেক জাবেদায় নয় ([[StandardChart::accountFor()]]); মূলধন না থাকলে সংরক্ষিত মুনাফা আগের মতো
    private function openingEquity(): \App\Modules\Accounts\Models\Account
    {
        if ((bool) app(\App\Core\Services\SettingsService::class)->get('accounts.opening_to_capital', true)) {
            return StandardChart::find(StandardChart::OWNER_CAPITAL) ?? StandardChart::accountFor(StandardChart::RETAINED_EARNINGS);
        }

        return StandardChart::accountFor(StandardChart::RETAINED_EARNINGS);
    }

    private function post(
        string $partyType,
        int $partyId,
        string $documentNo,
        string $amount,
        Carbon|string|null $date,
        string $partyAccount,
        bool $partyIsDebit,
        ?int $branchId = null,
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

        // ⛔ খাত কোড ধরে, মানে ধরেই — না পেলে থামা, ভুল খাতে বা অর্ধেক জাবেদায় নয় ([[StandardChart::accountFor()]])
        $party = StandardChart::accountFor($partyAccount);
        $equity = $this->openingEquity();

        // ⓘ পক্ষের কোড বিবরণে — খাতার নম্বর এখন OB সিরিজের ([[SERIES]])
        $narration = $this->narration().' · '.$documentNo;

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

        return $this->announce($equity, $this->numbered(
            sourceType: $partyType.self::SOURCE_SUFFIX,
            sourceId: $partyId,
            trxDate: $this->dateFor($date),
            lines: [$partyLine, $equityLine],
            /*
             * ⭐ পক্ষের নিজের শাখায় — অডিট ⓘ১৭ (৬ অক্টোবর ২০২৬)। ⛔ আগে শাখা পাঠানো হত না, তাই খোলা জের বসত যিনি ঢোকালেন
             * তাঁর শাখায় (পোস্টিংয়ের ডিফল্ট) — নেত্রকোনার গ্রাহকের পুরনো বাকি ময়মনসিংহের খাতায়। শাখা না দিলে আগের মতো।
             */
            branchId: $branchId,
        ));
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
    /**
     * ⭐ OB নম্বর নিয়ে দাখিলা — এক লেনদেনে, যাতে দাখিলা না বসলে নম্বরও খরচ না হয় (ফাঁক ছাড়া; [[SERIES]])।
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<LedgerEntry>
     */
    private function numbered(string $sourceType, int $sourceId, Carbon $trxDate, array $lines, ?int $branchId = null): array
    {
        return DB::transaction(fn () => $this->posting->post(
            sourceType: $sourceType,
            sourceId: $sourceId,
            trxDate: $trxDate,
            lines: $lines,
            documentNo: app(NumberSeriesEngine::class)->next(self::SERIES, $branchId, $trxDate, $sourceType, $sourceId),
            branchId: $branchId,
        ));
    }

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
         * ⛔ ভবিষ্যতের তারিখে নয় — ফর্ম আর আমদানি দুটোই তারিখ নিত, আর শুরুর জের পরের মাসে বসে আজকের বকেয়া, সীমার যাচাই আর
         * রেওয়ামিল থেকে লুকাত (Customer/Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[AnOpeningBalanceDatedNextMonthTest]])।
         * ⓘ সব পথ এখান দিয়ে যায় — গ্রাহক, সরবরাহকারী, খাত।
         */
        if ($given->copy()->startOfDay()->gt(Carbon::today())) {
            throw \Illuminate\Validation\ValidationException::withMessages(['opening_date' => __('accounts::validation.opening_in_future')]);
        }

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
