<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * কী কী ধরনের খবর পাঠানো হয় — সেটিংসের পর্দা এই তালিকাটাই দেখায়।
 *
 * ── কেন একটা লেখা তালিকা, ডাটাবেজ থেকে গোনা নয় ──────────────────────
 * পাঠানো খবরগুলো থেকে ধরন গোনা যেত (`select distinct type`)। ⛔ কিন্তু
 * তাতে **যে খবর এখনো কেউ পাননি সেটা সেটিংসে থাকত না** — অর্থাৎ যে খবরটা
 * আপনি আগেভাগে বন্ধ করতে চান, ঠিক সেটাই প্রথমবার এসে পড়ত।
 *
 * ⓘ তালিকায় না থাকা ধরন বন্ধ করা যায় না, কিন্তু পাঠানো আটকায়ও না —
 * নতুন কোনো খবর যোগ করে এখানে সারি লিখতে ভুলে গেলে সেটা সবাই পাবেন,
 * আর সেটাই নিরাপদ দিক ([[NotificationChoice]])।
 */
final class NotificationKinds
{
    /**
     * ধরন → ভাষার চাবি, পর্দায় যে ক্রমে দেখানো হয়।
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'approval.approved' => 'core.notify.kind.approval_approved',
            'approval.rejected' => 'core.notify.kind.approval_rejected',
            'report_ready' => 'core.notify.kind.report_ready',

            /*
             * ⭐ তারিখের আগাম খবর — ২১ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ নাম দুইটা চিহ্নমাত্র — কোর কোনো মডিউলের ক্লাস চেনে না,
             * শুধু পাঠানো খবরের ধরনটা জানে — অনুমোদনের দুইটা সারিও
             * ঠিক তাই। ⚠️ তালিকায় না বসালে খবরটা যেত, কিন্তু কেউ সেটা
             * বন্ধ করতে পারতেন না — আর যে খবর বন্ধ করা যায় না, সেটা
             * একদিন সবাই না-দেখা শিখে যান।
             */
            'finance.deposit_maturing' => 'core.notify.kind.deposit_maturing',
            'finance.hand_loan_due' => 'core.notify.kind.hand_loan_due',
        ];
    }

    public static function knows(string $type): bool
    {
        return array_key_exists($type, self::all());
    }
}
