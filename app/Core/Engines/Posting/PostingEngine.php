<?php

declare(strict_types=1);

namespace App\Core\Engines\Posting;

use App\Core\Support\CompanyContext;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    /**
     * একটা ডকুমেন্টের সব হিসাব একসাথে বসানো।
     *
     * @param  list<array{account_id: int, debit?: string|float|int, credit?: string|float|int, party_type?: string, party_id?: int, narration?: string, source_line_id?: int, branch_id?: int}>  $lines
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

        $financialYear = $this->resolveFinancialYear($trxDate);
        $this->assertBalanced($lines, $sourceType, $sourceId);
        $this->assertNotAlreadyPosted($sourceType, $sourceId);

        $branchId = $branchId ?? CompanyContext::branchId();
        $userId = $userId ?? auth()->id();

        return DB::transaction(function () use ($lines, $sourceType, $sourceId, $trxDate, $documentNo, $branchId, $userId, $financialYear) {
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
        $financialYear = $this->resolveFinancialYear($reversalDate);
        $userId = $userId ?? auth()->id();

        $reversalType = $sourceType.':reversal';

        $this->assertNotAlreadyPosted($reversalType, $sourceId);

        return DB::transaction(function () use ($original, $reversalType, $sourceId, $reversalDate, $financialYear, $reason, $userId) {
            $created = [];

            foreach ($original as $entry) {
                $created[] = LedgerEntry::create([
                    'company_id' => $entry->company_id,
                    'branch_id' => $entry->branch_id,
                    'financial_year_id' => $financialYear->id,
                    'account_id' => $entry->account_id,
                    'party_type' => $entry->party_type,
                    'party_id' => $entry->party_id,
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
     * একই ডকুমেন্ট দুইবার পোস্ট হলে হিসাব দ্বিগুণ দেখাবে।
     *
     * বাস্তবে এটা ঘটে সরল কারণে: ব্যবহারকারী Save-এ দুইবার ক্লিক করে, বা
     * নেটওয়ার্ক ধীর হলে রিকোয়েস্ট দুইবার যায়।
     */
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
