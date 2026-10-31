<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Support\ListSummary;

/**
 * ⭐ খসড়ার অনুমোদনের পাঁচ অবস্থা — পাতার নোট, ফোনের সারি, অপেক্ষার তালিকা আর তালিকার মোট সবাই [[VoucherApproval::decide()]]-এর
 * এই এক উত্তর পড়ে (তালিকার মোটের চুক্তি v6 — fe, a4, a6, ২৪ অক্টোবর ২০২৬)।
 *   AWAITING — অনুরোধ গেছে, আর তারপর ভাউচার বদলায়নি: সিদ্ধান্তের অপেক্ষা;
 *   STALE    — অনুরোধের পরে ভাউচার বদলেছে: "পোস্ট" চাপলে পুরনোটা তুলে নতুন করে অনুমোদনে যাবে;
 *   NEEDS    — অনুরোধ হয়নি, কিন্তু ছক বলছে পোস্টে অনুমোদন লাগবে;
 *   SIGNED   — অনুমোদন আছে — লেখক পোস্ট করবেন;
 *   REJECTED — প্রত্যাখ্যাত, আর তারপর ভাউচার বদলায়নি;
 *   null     — অনুমোদন লাগেই না।
 *
 * ⓘ আলাদা ক্লাসে, [[VoucherApproval]]-এ নয় (fe, ৩১ অক্টোবর ২০২৬): ওর প্রতিটা স্ট্রিং ধ্রুবক অনুমোদনের **কাজ**
 * (`accounts.payment`, …) — [[EveryApprovalAskedForCanBeConfiguredTest]] সেগুলো ছকে বসানো যায় কি না মাপে। অবস্থার নাম কাজ নয়;
 * ওখানে থাকলে "accounts.awaiting"-এর ছক খুঁজত।
 */
final class VoucherSigning
{
    public const AWAITING = 'awaiting';

    public const STALE = 'stale';

    public const NEEDS = 'needs';

    public const SIGNED = 'signed';

    public const REJECTED = 'rejected';

    /** অবস্থা থেকে তালিকার মোটের দল — বাকি সব (stale, needs, signed, null) খসড়া */
    public const GROUP_OF = [self::AWAITING => ListSummary::PENDING, self::REJECTED => ListSummary::REJECTED];
}
