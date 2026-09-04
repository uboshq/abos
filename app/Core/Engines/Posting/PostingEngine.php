<?php

declare(strict_types=1);

namespace App\Core\Engines\Posting;

use App\Core\Services\OpenPeriod;
use App\Core\Support\CompanyContext;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * হিসাবের খাতায় লেখার একমাত্র পথ — প্ল্যান সেকশন ২.২, প্রথম engine।
 *
 * প্রতিটা মডিউল এখানে আসে। বিক্রয়, ক্রয়, বেতন, ঋণের সুদ — সবাই নিজের
 * source_type নিয়ে একই দরজা দিয়ে ঢোকে। এই এক দরজার কারণেই ট্রায়াল ব্যালেন্স
 * সবসময় মেলে, ড্রিল-ডাউন সবসময় কাজ করে, আর নতুন মডিউল যোগ হলে হিসাবের
 * রিপোর্টে কিছু বদলাতে হয় না।
 */
final class PostingEngine
{
    public function __construct(
        private readonly OpenPeriod $period,
    ) {}

    /**
     * একটা ডকুমেন্টের সব হিসাব একসাথে বসানো।
     *
     * @param  list<array{account_id: int, debit?: string|float|int, credit?: string|float|int, party_type?: string, party_id?: int, cost_center_id?: int, narration?: string, source_line_id?: int, branch_id?: int}>  $lines
     * @return list<LedgerEntry>
     */
    public function post(
        string $sourceType,
        int $sourceId,
        Carbon|string $trxDate,
        array $lines,
        ?string $documentNo = null,
        ?int $branchId = null,
        ?int $userId = null,
    ): array {
        if ($lines === []) {
            throw new PostingException('Nothing to post — a document with no ledger lines has no effect on the books.');
        }

        $trxDate = $trxDate instanceof Carbon ? $trxDate : Carbon::parse($trxDate);

        /*
         * বন্ধ মাস ও পেছনের জানালা — এক দরজার ঠিক ভেতরে।
         *
         * ── কেন এখানে, প্রতিটা সার্ভিসে নয় ──────────────────────────
         * খতিয়ানে লেখার পথ একটাই, আর সেটাই এই ফাংশন। প্রতিটা মডিউলে
         * আলাদা করে পাহারা বসালে একদিন কোনো একটা নতুন কাগজ ওটা বসাতে
         * ভুলে যেত — আর ভুলটা ধরা পড়ত বছর শেষে, বন্ধ মাসে একটা এন্ট্রি
         * দেখে।
         *
         * অর্থবছরের তালাটা ঠিক নিচেই (`resolveFinancialYear`), আর
         * তিনটা স্তর একসাথেই সত্যি: বছর, মাস, আর কত দিন পেছনে।
         */
        $this->period->assertOpen($trxDate);

        $financialYear = $this->resolveFinancialYear($trxDate);
        $this->assertBalanced($lines, $sourceType, $sourceId);
        $this->assertAccountsCanHoldMoney($lines, $sourceType, $sourceId);
        $this->assertNotAlreadyPosted($sourceType, $sourceId);

        $branchId = $branchId ?? CompanyContext::branchId();
        $userId = $userId ?? auth()->id();

        return DB::transaction(function () use ($lines, $sourceType, $sourceId, $trxDate, $documentNo, $branchId, $userId, $financialYear) {
            /*
             * উপরের `assertNotAlreadyPosted()` ভদ্রতা; আসল পাহারা এটা।
             *
             * ওটা লেনদেনের বাইরে চলে, তাই দুইটা রিকোয়েস্ট একসাথে এলে
             * দুইজনেই "বসেনি" দেখে। এই সারিটা লেনদেনের ভেতরে বসে, আর
             * দ্বিতীয়জন unique key-তে ধাক্কা খেয়ে পুরো লেনদেন ফিরিয়ে দেয়।
             */
            $this->claim($sourceType, $sourceId, $userId);

            $created = [];

            foreach ($lines as $index => $line) {
                $debit = $this->normalise($line['debit'] ?? 0);
                $credit = $this->normalise($line['credit'] ?? 0);

                // একই লাইনে ডেবিট ও ক্রেডিট দুটোই থাকলে সেটা আসলে দুইটা লাইন।
                // মিলিয়ে লিখলে লেজারে ওই খাতের প্রকৃত চলাচল আর দেখা যায় না।
                if (bccomp($debit, '0', 4) > 0 && bccomp($credit, '0', 4) > 0) {
                    throw new PostingException(
                        "Line {$index} of {$sourceType}#{$sourceId} carries both a debit and a credit. "
                        .'Split it into two lines so the account movement stays readable.'
                    );
                }

                if (bccomp($debit, '0', 4) === 0 && bccomp($credit, '0', 4) === 0) {
                    throw new PostingException(
                        "Line {$index} of {$sourceType}#{$sourceId} is zero on both sides."
                    );
                }

                $created[] = LedgerEntry::create([
                    'company_id' => CompanyContext::id(),
                    'branch_id' => $line['branch_id'] ?? $branchId,
                    'financial_year_id' => $financialYear->id,
                    'account_id' => $line['account_id'],
                    'party_type' => $line['party_type'] ?? null,
                    'party_id' => $line['party_id'] ?? null,

                    /*
                     * খরচের কেন্দ্র — সারিতে, ডকুমেন্টের মাথায় নয়।
                     *
                     * একটা ভাউচারে দুই রুটের খরচ থাকতে পারে। মাথায়
                     * রাখলে ওটা লিখতে দুইটা ভাউচার লাগত, আর মানুষ তখন
                     * একটাতেই লিখে দিতেন — আর রুট ধরে খরচের হিসাব
                     * নীরবে ভুল হত।
                     */
                    'cost_center_id' => $line['cost_center_id'] ?? null,

                    'trx_date' => $trxDate->toDateString(),
                    'debit' => $debit,
                    'credit' => $credit,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'source_line_id' => $line['source_line_id'] ?? null,
                    'document_no' => $documentNo,
                    'narration' => $line['narration'] ?? null,
                    'created_by' => $userId,
                ]);
            }

            return $created;
        });
    }

    /**
     * একটা ডকুমেন্ট বাতিল — পুরনো এন্ট্রি মোছা হয় না, উল্টো এন্ট্রি বসে।
     *
     * মুছে ফেললে নিরীক্ষায় "৪৭ নম্বর ভাউচারটা কোথায়" প্রশ্নের উত্তর থাকে না।
     * উল্টো এন্ট্রি রাখলে দুটোই দেখা যায়: কী হয়েছিল, আর কীভাবে ফেরানো হলো।
     *
     * @return list<LedgerEntry>
     */
    public function reverse(
        string $sourceType,
        int $sourceId,
        Carbon|string $reversalDate,
        ?string $reason = null,
        ?int $userId = null,
    ): array {
        $original = LedgerEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->orderBy('id')
            ->get();

        if ($original->isEmpty()) {
            throw new PostingException(
                "Nothing to reverse — {$sourceType}#{$sourceId} has no ledger entries."
            );
        }

        $reversalDate = $reversalDate instanceof Carbon ? $reversalDate : Carbon::parse($reversalDate);

        /*
         * উল্টো এন্ট্রিও বন্ধ মাসে বসে না।
         *
         * এটা বাতিল করাকে আটকায় না — বাতিলের তারিখ সাধারণত আজ, আর আজকের
         * মাস খোলা। আটকায় কেবল **বন্ধ মাসের ভেতরে** উল্টো এন্ট্রি বসানো,
         * কারণ সেটা ছাপা হয়ে যাওয়া হিসাব বদলে দিত — আর তালার পুরো
         * উদ্দেশ্যই তাই।
         */
        $this->period->assertOpen($reversalDate);

        $financialYear = $this->resolveFinancialYear($reversalDate);
        $userId = $userId ?? auth()->id();

        $reversalType = $sourceType.':reversal';

        $this->assertNotAlreadyPosted($reversalType, $sourceId);

        return DB::transaction(function () use ($original, $reversalType, $sourceId, $reversalDate, $financialYear, $reason, $userId) {
            // উল্টো এন্ট্রিও একবারই — দুইবার বাতিল করলে হিসাব উল্টোদিকে
            // দ্বিগুণ হত, আর সেটাও রেওয়ামিল মেলা অবস্থাতেই
            $this->claim($reversalType, $sourceId, $userId);

            $created = [];

            foreach ($original as $entry) {
                $created[] = LedgerEntry::create([
                    'company_id' => $entry->company_id,
                    'branch_id' => $entry->branch_id,
                    'financial_year_id' => $financialYear->id,
                    'account_id' => $entry->account_id,
                    'party_type' => $entry->party_type,
                    'party_id' => $entry->party_id,
                    'cost_center_id' => $entry->cost_center_id,
                    'trx_date' => $reversalDate->toDateString(),
                    // পক্ষ উল্টে যায় — যা ডেবিট ছিল তা ক্রেডিট হয়
                    'debit' => $entry->credit,
                    'credit' => $entry->debit,
                    'source_type' => $reversalType,
                    'source_id' => $sourceId,
                    'source_line_id' => $entry->source_line_id,
                    'document_no' => $entry->document_no,
                    'narration' => $reason ?? __('core.posting.reversal_of', ['document' => $entry->document_no]),
                    'created_by' => $userId,
                ]);
            }

            return $created;
        });
    }

    /**
     * ডেবিটের যোগফল = ক্রেডিটের যোগফল — নাহলে কিছুই বসবে না।
     *
     * bcmath দিয়ে মেলানো হয়, float দিয়ে নয়: 0.1 + 0.2 float-এ 0.3 হয় না,
     * আর দশ হাজার লাইনের পর সেই ভুলটা টাকায় দেখা দেয়।
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertBalanced(array $lines, string $sourceType, int $sourceId): void
    {
        $debit = '0';
        $credit = '0';

        foreach ($lines as $line) {
            $debit = bcadd($debit, $this->normalise($line['debit'] ?? 0), 4);
            $credit = bcadd($credit, $this->normalise($line['credit'] ?? 0), 4);
        }

        if (bccomp($debit, $credit, 4) !== 0) {
            $difference = bcsub($debit, $credit, 4);

            throw new PostingException(
                "{$sourceType}#{$sourceId} does not balance: debit {$debit} against credit {$credit}, "
                ."a difference of {$difference}. Nothing was written."
            );
        }
    }

    /**
     * প্রতিটা সারির খাতটা সত্যিই টাকা ধরতে পারে কি না।
     *
     * ⛔ ── কী ভাঙা ছিল ───────────────────────────────────────────────
     * এই ইঞ্জিন খাতটা নিয়ে **কিছুই দেখত না** — `account_id` যা আসত,
     * হুবহু বসে যেত। তিনটা প্রশ্নের একটারও উত্তর নেওয়া হত না: খাতটা
     * আছে কি · এই কোম্পানির কি · **দাখিলা ধরতে পারে কি**।
     *
     * ── কেন তৃতীয়টা সবচেয়ে বিপজ্জনক ─────────────────────────────────
     * `Account::balanceOn()` একটা **দলের** ক্ষেত্রে কেবল সন্তানদের যোগ
     * করে, দলের নিজের সারিগুলো নয়। তাই একটা দলে বসে যাওয়া টাকা —
     *
     *     খতিয়ানে সারিটা থাকে ✅   ·   কোনো যোগফলে আসে না ⛔
     *
     * ⚠️ **আর কোনো পাহারা এটা ধরত না।** `EveryVoucherBalances` সবুজ
     * থাকে, কারণ **ডেবিট আর ক্রেডিট তো মিলছেই** — কেবল টাকাটা কোনো
     * রিপোর্টে নেই। ⓘ নীরব ক্ষতির এর চেয়ে নিখুঁত রূপ কম।
     *
     * ── কেন ব্যতিক্রম, কেবল একটা টেস্ট-গার্ড নয় ─────────────────────
     * টেস্ট **আমাদের কোড** পাহারা দেয়, **ক্রেতার চার্ট** নয়। একজন
     * ক্রেতা নিজের ছকে একটা খাতকে দল বানিয়ে ফেললে আমাদের কোনো টেস্ট
     * সেটা জানবে না — কিন্তু টাকাটা ঠিকই উধাও হবে।
     *
     * ── আর এটা কারো কাজ থামাবে না ───────────────────────────────────
     * মেপে দেখা হয়েছে: **আজ কোনো বৈধ পথ দলে পোস্ট করে না।** প্রতিটা
     * পোস্টিং-পথ হয় `->postable()` ছাঁকা তালিকা থেকে খাত নেয়, নয়
     * `StandardChart::find(<পোস্টিং কোড>)` থেকে। ⭐ তাই ব্যতিক্রমটা
     * কেবল **ভুল** কাজ থামায়, বৈধ কোনোটা নয়।
     *
     * ── কেন এক কোয়েরিতে ────────────────────────────────────────────
     * সারিপ্রতি একটা করে খোঁজা মানে বিশ লাইনের ভাউচারে বিশটা কোয়েরি।
     * ⓘ আজই আমরা ঠিক এই ধরনের একটা N+1 সারিয়েছি, তাই দ্বিতীয়টা
     * বানানো হয়নি।
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertAccountsCanHoldMoney(array $lines, string $sourceType, int $sourceId): void
    {
        $wanted = array_values(array_unique(array_map(
            static fn (array $line): int => (int) ($line['account_id'] ?? 0),
            $lines,
        )));

        /*
         * খাতগুলো এক কোয়েরিতে — আর টেবিলটা নাম ধরে, মডেল ধরে নয়।
         *
         * ⚠️ ── কেন `Account` মডেল ব্যবহার করা যায় না ─────────────────
         * `Account` থাকে `App\Modules\Accounts`-এ, আর **কোর কোনো
         * মডিউলের নাম জানে না** — নিয়মটা `BoundariesTest`-এ বাঁধা।
         * ⓘ মডেলটা import করলে খতিয়ানের ইঞ্জিন চিরকাল Accounts মডিউল
         * ছাড়া চলত না, অথচ ওটা কোরের সবচেয়ে নিচের স্তর।
         *
         * ⓘ `ledger_entries` টেবিলটাও এখানে নাম ধরেই লেখা হয় — একই কারণে।
         *
         * ── আর কোম্পানির সীমাটা স্পষ্ট, স্কোপের ভরসায় নয় ────────────
         * মডেল ব্যবহার করলে global scope এমনিতেই ছাঁকত। কোয়েরি বিল্ডারে
         * সেটা নেই, **আর সেটাই এখানে ভালো**: এই পাহারার একটা কাজই হলো
         * *"খাতটা এই কোম্পানির তো?"* — আর ওই প্রশ্নের উত্তর একটা নীরব
         * স্কোপের হাতে ছেড়ে দেওয়া চলে না।
         */
        $found = DB::table('accounts')
            ->whereIn('id', $wanted)
            ->where('company_id', CompanyContext::id())
            ->get(['id', 'code', 'is_group'])
            ->keyBy('id');

        foreach ($lines as $index => $line) {
            $id = (int) ($line['account_id'] ?? 0);
            $account = $found->get($id);

            if ($account === null) {
                throw new PostingException(
                    "Line {$index} of {$sourceType}#{$sourceId} points at account {$id}, which does not "
                    .'exist in this company. Nothing was written.'
                );
            }

            if ($account->is_group) {
                throw new PostingException(
                    "Line {$index} of {$sourceType}#{$sourceId} posts to {$account->code}, which is a group. "
                    .'A group holds no entries of its own — its balance is the sum of its children — so the '
                    .'money would sit in the ledger and appear in no report at all. Post to one of its '
                    .'children instead. Nothing was written.'
                );
            }
        }
    }

    /**
     * একই ডকুমেন্ট দুইবার পোস্ট হলে হিসাব দ্বিগুণ দেখাবে।
     *
     * বাস্তবে এটা ঘটে সরল কারণে: ব্যবহারকারী Save-এ দুইবার ক্লিক করে, বা
     * নেটওয়ার্ক ধীর হলে রিকোয়েস্ট দুইবার যায়।
     */
    /**
     * ডকুমেন্টটার নাম প্রহরী-টেবিলে লিখে রাখা — লেনদেনের ভেতরে।
     *
     * ── কেন এটা লাগল ────────────────────────────────────────────────
     * `assertNotAlreadyPosted()` একটা check-then-act: দেখা আর বসানোর
     * মাঝখানে অন্য কেউ ঢুকে পড়তে পারে। সেটা কল্পনা নয় — Save-এ দুইবার
     * ক্লিক বা ব্রাউজারের পুনরায় পাঠানোই যথেষ্ট।
     *
     * এই সারিটা বসে ঠিক ওই লেনদেনে যেটা খতিয়ানের সারিগুলো বসায়, আর
     * টেবিলে `(company_id, source_type, source_id)` unique। তাই
     * দ্বিতীয়জন এখানেই থামে, আর তার কিছুই বসে না।
     *
     * ── কেন ব্যতিক্রমটা অনুবাদ করা হয় ───────────────────────────────
     * কাঁচা duplicate-key বার্তাটা ডাকা কোডের কাছে অর্থহীন। একই
     * PostingException ছুঁড়লে দুইটা পথই — ভদ্র চেক আর ডাটাবেজের
     * পাহারা — ডাকা কোডের কাছে একরকম দেখায়।
     */
    private function claim(string $sourceType, int $sourceId, ?int $userId): void
    {
        try {
            DB::table('posted_documents')->insert([
                'public_id' => (string) Str::uuid(),
                'company_id' => CompanyContext::id(),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'posted_at' => now(),
                'posted_by' => $userId,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new PostingException(
                "{$sourceType}#{$sourceId} is already in the ledger. Reverse it before posting again."
            );
        }
    }

    private function assertNotAlreadyPosted(string $sourceType, int $sourceId): void
    {
        $exists = LedgerEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();

        if ($exists) {
            throw new PostingException(
                "{$sourceType}#{$sourceId} is already in the ledger. Reverse it before posting again."
            );
        }
    }

    private function resolveFinancialYear(Carbon $date): FinancialYear
    {
        $year = FinancialYear::forDate($date);

        if ($year === null) {
            throw new PostingException(
                "No financial year covers {$date->toDateString()}. Create one before posting to that date."
            );
        }

        if ($year->is_closed) {
            throw new PostingException(
                "Financial year {$year->name} is closed. Reopening it is an approved action, not a side effect of posting."
            );
        }

        return $year;
    }

    private function normalise(string|float|int $amount): string
    {
        return bcadd((string) $amount, '0', 4);
    }
}
