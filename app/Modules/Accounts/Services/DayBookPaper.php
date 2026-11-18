<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

/**
 * ⭐ দৈনিক খতিয়ানের কাগজকে পাতায় ভাগ — প্রতিটা এন্ট্রি আস্ত এক পাতায়, পাতার নিচে "পরের পৃষ্ঠায় জের →", পরের পাতার মাথায়
 * "← পূর্ব পৃষ্ঠার জের" (মালিকের অনুমোদিত নমুনা, ১৬ নভেম্বর ২০২৬)।
 *
 * ── কেন পাতা ভাগ এখানে, ছাপার যন্ত্রে নয় ──────────────────────────
 * mPDF নিজে পাতা ভাঙে, কিন্তু কোন পাতায় কোন সারি পড়ল তা জানায় না — তাই পাতার শেষে জের লেখা যায় না। এখানে প্রতিটা সারির
 * একটা "উচ্চতা" (একক) ধরে পাতা ভরা হয়: এন্ট্রির প্রথম সারি দুই লাইন (পার্টি আর বিবরণ), বাকি লাইন এক, জার্নালের মাথা ও যোগফল এক,
 * জেরের সারি এক। বাজেট ইচ্ছে করে একটু কম, যাতে mPDF নিজে কখনো বাড়তি পাতা না খোলে —
 * [[TheDayBookIsAJournalOnPaperTest]] ছাপা PDF-এর পাতা গুনে মেলায়।
 */
final class DayBookPaper
{
    /** প্রথম পাতায় কোম্পানির মাথা আর তথ্যের লাইন থাকে — জায়গা কম */
    public const FIRST_PAGE = 40;

    public const OTHER_PAGES = 50;

    /**
     * @param  array{journals: list<array{key: string, entries: list<array<string, mixed>>, debit: string, credit: string}>}  $book
     * @return list<array{rows: list<array<string, mixed>>, carry_in: ?array{debit: string, credit: string}, carry_out: ?array{debit: string, credit: string}}>
     */
    public static function pages(array $book, int $first = self::FIRST_PAGE, int $other = self::OTHER_PAGES): array
    {
        // ⓘ প্রথমে সারির সারি: এন্ট্রি একটা অবিভাজ্য টুকরো
        $blocks = [];

        foreach ($book['journals'] as $journal) {
            $blocks[] = ['kind' => 'journal', 'journal' => $journal['key'], 'units' => 1];

            foreach ($journal['entries'] as $entry) {
                $blocks[] = ['kind' => 'entry', 'entry' => $entry, 'units' => self::unitsOf($entry)];
            }

            $blocks[] = ['kind' => 'total', 'journal' => $journal['key'], 'count' => count($journal['entries']),
                'debit' => $journal['debit'], 'credit' => $journal['credit'], 'units' => 1];
        }

        $pages = [];
        $rows = [];
        $budget = $first;
        $used = 0;
        $runDebit = '0';
        $runCredit = '0';
        $carryIn = null;

        foreach ($blocks as $i => $block) {
            // ⓘ জার্নালের মাথা একা পাতার শেষে পড়ে থাকবে না — পরের এন্ট্রিসহ জায়গা লাগে
            $needs = $block['units'] + ($block['kind'] === 'journal' ? (int) ($blocks[$i + 1]['units'] ?? 0) : 0);

            // ⓘ পাতা ভরলে: নিচে জেরের একটা সারির জায়গা রেখে নতুন পাতা
            if ($rows !== [] && $used + $needs + 1 > $budget) {
                $carry = ['debit' => $runDebit, 'credit' => $runCredit];
                $pages[] = ['rows' => $rows, 'carry_in' => $carryIn, 'carry_out' => $carry];
                $rows = [];
                $carryIn = $carry;
                $budget = $other;
                $used = 1; // ⓘ মাথায় "পূর্ব পৃষ্ঠার জের"
            }

            $rows[] = $block;
            $used += $block['units'];

            if ($block['kind'] === 'entry') {
                $runDebit = bcadd($runDebit, (string) $block['entry']['debit'], 4);
                $runCredit = bcadd($runCredit, (string) $block['entry']['credit'], 4);
            }
        }

        $pages[] = ['rows' => $rows, 'carry_in' => $carryIn, 'carry_out' => null];

        return $pages;
    }

    /** @param  array<string, mixed>  $entry */
    public static function unitsOf(array $entry): int
    {
        // ⓘ প্রথম লাইনের ঘরে পার্টির নাম আর নিচে বিবরণ — দুই লাইন উঁচু; বাকি প্রতিটা হিসাব-লাইন এক
        $lines = max(1, count($entry['lines'] ?? []));

        return 2 + ($lines - 1);
    }
}
