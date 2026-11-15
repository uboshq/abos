<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Support;

use App\Core\Engines\Print\PrintEngine;

/**
 * ⭐ হিসাব বিবরণীর পাতা ভাগ — প্রতি পাতায় যত সারি ধরে তত (মালিক, ১৫ নভেম্বর ২০২৬: "প্রতিটা পৃষ্ঠায় সর্বোচ্চ পরিমাণে লাইন দিবা,
 * যাতে করে কাগজের অপচয় কম হয়")।
 *
 * ── কেন নিজে ভাগ করা, mPDF-এর নিজের পাতা-ভাঙা নয় ──────────────────────────────────────────
 * প্রতি পাতার শেষে "পরের পৃষ্ঠায় জের" আর পরের পাতার শুরুতে "পূর্ব পৃষ্ঠার জের" — ব্যাংকের নিয়ম। mPDF নিজে পাতা ভাঙলে কোন সারিতে
 * ভাঙল তা আগে জানা যায় না, তাই জেরের সারি বসানো যায় না। ⓘ এখানে প্রতিটা সারির উচ্চতা আগেই মাপা হয় — বিবরণের লেখা কাগজের
 * নিজের ফন্টে মেপে ([[PrintEngine::textWidth()]]) কয় লাইনে ভাঙবে — তারপর পাতা ভরে।
 * ⓘ মাপে সামান্য ভুল হলে ছাঁচের টেবিল `page-break-inside: avoid` — mPDF সেই পাতার টেবিল একটু ছোট করে এক পাতাতেই রাখে।
 *
 * সব মাপ মিলিমিটারে, A4 খাড়া; ছাঁচের CSS-এর সাথে মিল রেখে ([[party-statement]] ব্লেড)।
 */
final class PartyStatementPages
{
    /** পাতার লেখার উচ্চতা: ২৯৭ − উপরে ১০ − নিচে ১৪ (পৃষ্ঠা নম্বরের জায়গাসহ) */
    public const BODY = 273.0;

    /** প্রথম পাতার মাথা — প্যাড আর দুই তথ্যের বাক্স */
    public const FIRST_HEAD = 43.0;

    /** পরের পাতাগুলোর মাথা — দলের নাম আর সময়কালের এক লাইন */
    public const RUN_HEAD = 8.2;

    /** কলামের শিরোনামের সারি */
    public const TABLE_HEAD = 7.5;

    /** জেরের সারি (শুরুর / পূর্ব / পরের) আর "মোট" সারি */
    public const CARRY = 6.2;

    /** এক লাইনের সারি; প্রতিটা বাড়তি লাইনে [[LINE]] যোগ */
    public const ROW = 6.2;

    public const LINE = 3.5;

    /** শেষ পাতার নিচে: মেলানোর হিসাব */
    public const RECON = 27.0;

    /** শেষ পাতার নিচে: সইয়ের দুই ঘর (চালু থাকলে) */
    public const SIGNS = 24.0;

    /** বিবরণের কলামের লেখার চওড়া (কলাম ৫৪ − দুই পাশের প্যাডিং) আর লেখার মাপ */
    public const PARTICULARS_WIDTH = 51.6;

    public const FONT_PT = 8.5;

    public function __construct(private readonly PrintEngine $print) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<array<string, mixed>>> পাতা ধরে সারি; সারি না থাকলেও একটা পাতা
     */
    public function split(array $rows, bool $signatures, float $shrink = 0.0): array
    {
        $tail = self::CARRY + self::RECON + ($signatures ? self::SIGNS : 0.0);
        $pages = [];
        $page = [];
        $used = 0.0;
        $room = $this->room(true) - $shrink;

        foreach ($rows as $i => $row) {
            $height = $this->height((string) $row['particulars']);
            $isLast = $i === array_key_last($rows);

            // ⓘ এই সারির পরে "পরের পৃষ্ঠায় জের" (পাতা চলবে), নয়তো মোট আর মেলানো (শেষ সারি) — দুটোর জায়গা রেখে
            $needed = $used + $height + ($isLast ? $tail : self::CARRY);

            if ($page !== [] && $needed > $room) {
                $pages[] = $page;
                $page = [];
                $used = 0.0;
                $room = $this->room(false) - $shrink;
            }

            $page[] = $row;
            $used += $height;
        }

        $pages[] = $page;

        // ⓘ শেষ পাতায় সারি আছে অথচ মোট আর মেলানো আঁটে না — তাদের জন্য নতুন পাতা নয়, শেষের সারিগুলো পরের পাতায় যায়
        $last = array_key_last($pages);
        $lastUsed = array_sum(array_map(fn ($r) => $this->height((string) $r['particulars']), $pages[$last]));

        if (count($pages[$last]) > 1 && $lastUsed + $tail > $this->room($last === 0) - $shrink) {
            $moved = [];

            while (count($pages[$last]) > 1 && $lastUsed + $tail > $this->room($last === 0) - $shrink) {
                $row = array_pop($pages[$last]);
                array_unshift($moved, $row);
                $lastUsed -= $this->height((string) $row['particulars']);
            }

            $pages[] = $moved;
        }

        return $pages;
    }

    /**
     * শেষ পাতায় সারি, মোট আর মেলানোর পরে বাকি জায়গা, মিমি — সইয়ের ঘর পাতার একদম নিচে নামাতে (মালিক, ১৫ নভেম্বর ২০২৬:
     * সই পৃষ্ঠা নম্বরের ঠিক উপরে)। ⓘ মাপে ভুলের জন্য ৩ মিমি কম; ঋণাত্মক হয় না।
     *
     * @param  list<array<string, mixed>>  $rows  শেষ পাতার সারি
     */
    public function spare(array $rows, bool $firstPage, bool $signatures, float $shrink = 0.0): float
    {
        $used = array_sum(array_map(fn ($r) => $this->height((string) $r['particulars']), $rows));
        $tail = self::CARRY + self::RECON + ($signatures ? self::SIGNS : 0.0);

        return max(0.0, $this->room($firstPage) - $shrink - $used - $tail - 3.0);
    }

    /** পাতায় সারির জন্য জায়গা — মাথা, কলামের শিরোনাম আর উপরের জেরের সারি বাদে */
    private function room(bool $first): float
    {
        return self::BODY - ($first ? self::FIRST_HEAD : self::RUN_HEAD) - self::TABLE_HEAD - self::CARRY;
    }

    private function height(string $particulars): float
    {
        $width = $particulars === '' ? 0.0 : $this->print->textWidth($particulars, self::FONT_PT);
        $lines = max(1, (int) ceil($width / self::PARTICULARS_WIDTH));

        return self::ROW + ($lines - 1) * self::LINE;
    }
}
