<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * যে পাকা কাগজের বিপরীতে ক্রেডিট বা ডেবিট নোট কাটা যায় — বিক্রয়ের ইনভয়েস (পরে চাইলে ক্রয়ের বিল)।
 *
 * ── ⭐ কেন, মালিকের বিক্রয় পরিকল্পনা §৬, ৬ অক্টোবর ২০২৬ ─────────────────
 * "দামে ভুল: বেশি ধরা → ক্রেডিট নোট; কম ধরা → ডেবিট নোট।" ⛔ নোটের "কোন বিলের বিপরীতে" ঘরটা ছিল খালি লেখা — যাচাই
 * নেই: ভুল নম্বর, অন্য গ্রাহকের বিল, বা বিলের চেয়ে বড় ক্রেডিট — সবই চলত, আর বাতিল-ইনভয়েসের পাহারা
 * (`against_no`) সেই লেখাই বিশ্বাস করত।
 *
 * ── ⚠️ কেন চুক্তি ─────────────────────────────────────────────────────
 * Accounts কোনো মডিউলের নাম জানে না ([[SettledByAVoucher]]-এর একই কারণ)। ধরনের নামটা কাগজের নিজের module.php-র
 * `drill_sources`-এ, [[App\Core\Engines\Drill\DrillResolver]] খুলে দেয়, আর Accounts কেবল এই চুক্তি চেনে।
 */
interface NoteTarget
{
    /**
     * এই পক্ষের এই নম্বরের পাকা কাগজ — না থাকলে, অন্যের হলে বা বাতিল হলে `null`।
     *
     * @param  string  $partyType  খাতার `party_type` (`customer`, `supplier`)
     */
    public static function noteTargetFor(string $number, string $partyType, int $partyId): ?self;

    /** খাতার `source_type` — নোটের `against_type` */
    public function noteTargetType(): string;

    /** কাগজের নম্বর — নোটের `against_no` (বাতিল-ইনভয়েসের পাহারা এটাই পড়ে) */
    public function noteTargetNumber(): string;

    /**
     * ক্রেডিট নোট আর কত নিতে পারে — মোট থেকে ফেরত আর আগের (বাতিল নয়) ক্রেডিট নোট বাদে।
     *
     * @param  int|null  $exceptNoteId  যে নোটটা এখন পাকা হচ্ছে, সে নিজেকে গোনে না
     */
    public function noteCreditRoom(?int $exceptNoteId = null): string;

    /** কাগজের ভ্যাটের হার, ভগ্নাংশে (০.১৫ = ১৫%) — নোটের ভ্যাট এর বেশি নয় */
    public function noteTaxRate(): string;
}
