<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Support;

use App\Modules\MasterData\Models\ReasonCode;

/**
 * বিক্রয় ফেরতের আটটা আদি কারণ — NEXUS §২৪।
 *
 * ── ⭐ স্পেকের আটটা, আর ABOS-এ কোনটা কী ──────────────────────────────
 *   ভুল পণ্য            → WRONG      (আগে থেকেই ছিল)
 *   ক্ষতিগ্রস্ত          → DAMAGE     (আগে থেকেই ছিল)
 *   মেয়াদোত্তীর্ণ         → EXPIRED    (আগে থেকেই ছিল — এখন লট চায়)
 *   গ্রাহক নেননি         → REJECTED   নতুন
 *   ভুল পরিমাণ          → WRONG-QTY  নতুন
 *   মানের সমস্যা         → QUALITY    নতুন
 *   বাণিজ্যিক ফেরত       → UNSOLD     (আগে থেকেই ছিল — "বিক্রি হয়নি")
 *   অন্যান্য              → OTHER      নতুন — নোট চায়
 *
 * ⓘ বাণিজ্যিক ফেরত আর "বিক্রি হয়নি" একই ঘটনা: মালে দোষ নেই, দোকানে
 * বিকোয়নি, চুক্তিমতো ফেরত। ⛔ দুইটা আলাদা সারি বানালে রিপোর্টে একই
 * কারণ দুই ভাগে পড়ত আর কোনোটাই সত্যি অঙ্ক বলত না।
 *
 * ── ⚠️ কেন এখানে, [[MasterListService::reasonRows()]]-এর ভিতরে নয় ──────
 * তালিকাটা ঐ পদ্ধতিতেই মেশে (`...SalesReturnReasons::rows()`), ফলে নতুন
 * কোম্পানি [[installDefaults()]] দিয়ে আর চলমান কোম্পানি
 * `abos:sync-reason-codes` দিয়ে একই সারি পায় — ⛔ দুইটা আলাদা তালিকা
 * হলে একদিন একটায় যোগ হত আর অন্যটায় নয়।
 *
 * ⚠️ নামগুলো ইচ্ছা করে লম্বা ("Other return reason", "Other" নয়):
 * [[DuplicationEngine]] নাম মেলায় গোটা কারণ-তালিকায়, প্রসঙ্গ না দেখে।
 * ⛔ ছোট "Other" রাখলে কাল কেউ মজুদ সমন্বয়ের "Other" বসাতে গেলে আটকে যেত।
 */
final class SalesReturnReasons
{
    /** ⭐ স্পেকের ক্রমে — ফর্মের তালিকাও এভাবেই পড়া যায়। */
    public const CODES = ['WRONG', 'DAMAGE', 'EXPIRED', 'REJECTED', 'WRONG-QTY', 'QUALITY', 'UNSOLD', 'OTHER'];

    /**
     * যে চারটা আগে ছিল না — `reasonRows()`-এ এগুলোই যোগ হয়।
     *
     * ⚠️ বাকি চারটা (DAMAGE, EXPIRED, WRONG, UNSOLD) `reasonRows()`-এ আগে
     * থেকেই আছে; ⛔ এখানে আবার লিখলে একই কোড দুইবার বসার চেষ্টা হত।
     *
     * `returns_to_stock` — মালটা আবার বেচা যায় কি না:
     *   · গ্রাহক নেননি / ভুল পরিমাণ → মালে দোষ নেই, হ্যাঁ
     *   · মানের সমস্যা → না, যাচাই না হওয়া পর্যন্ত
     *   · অন্যান্য → না; ⓘ অজানা কারণে ফেরা মাল বিক্রয়যোগ্য ধরে নেওয়াটাই
     *     ঝুঁকির দিক, আটকে রাখাটা নিরাপদ দিক
     *
     * @return list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
     */
    public static function rows(): array
    {
        return [
            ['REJECTED', 'Customer rejected the goods', 'গ্রাহক মাল নেননি',
                ['context' => ReasonCode::SALES_RETURN, 'returns_to_stock' => true]],
            ['WRONG-QTY', 'Wrong quantity delivered', 'ভুল পরিমাণ দেওয়া হয়েছে',
                ['context' => ReasonCode::SALES_RETURN, 'returns_to_stock' => true]],
            ['QUALITY', 'Quality issue', 'মানের সমস্যা',
                ['context' => ReasonCode::SALES_RETURN, 'returns_to_stock' => false]],
            ['OTHER', 'Other return reason', 'ফেরতের অন্য কারণ',
                ['context' => ReasonCode::SALES_RETURN, 'returns_to_stock' => false,
                    'needs_note' => true]],
        ];
    }
}
