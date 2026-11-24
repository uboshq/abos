<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * ⭐ নোটের যে সারিতে মাল সত্যিই ফিরল, সেই সারির ফেরতের কাগজ — বিক্রয় ফেরত (SR) বা ক্রয় ফেরত (PR)।
 *
 * ── মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬ ───────────────────────────────────
 * ক্রেডিট/ডেবিট নোটের পণ্যের সারিতে "মাল ফেরত এসেছে/গেছে" টিক দিলে ফেরতের কাগজ নিজে তৈরি হয়, নোটের সাথে বাঁধা।
 * ⛔ ঐ সারির টাকা আর মাল **ফেরতের কাগজই** খাতায় তোলে — নোট আবার তোলে না, তাই কোনো টাকা দুবার ওঠে না।
 *
 * ⓘ Accounts কোনো মডিউলের নাম জানে না; বিলের মডেলই এই চুক্তি মানে ([[NoteTarget]]-এর একই পথ)।
 */
interface ReturnsGoodsForNotes
{
    /**
     * ফেরতের কাগজ বানিয়ে পাকা করা — নোট পাকা হওয়ার একই লেনদেনে।
     *
     * ⓘ নোটের সই (মালিক বা অনুমোদনকারী) এই ফেরতের সই — একই সিদ্ধান্ত দুবার চাওয়া হয় না।
     *
     * @param  list<array{line_id: int, product_id: int, qty: string}>  $lines
     * @param  bool  $approved  নোটটা সত্যিই অনুমোদিত কি না — না হলে ফেরতের নিজের অনুমোদনের ছক চলে
     * @return array{type: string, id: int, no: string, lines: list<array{line_id: int, amount: string, tax: string}>}
     */
    public function noteReturnGoods(array $lines, string $date, string $narration, string $noteNo, bool $approved = false): array;

    /** নোট বাতিল হলে তার ফেরতের কাগজও বাতিল — মাল আর খাতা দুটোই ফেরে */
    public static function noteCancelReturn(string $type, int $id, string $reason): void;
}
