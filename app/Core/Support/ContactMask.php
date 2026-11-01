<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * ⭐ কারও ইমেইল আর ফোন — পুরোটা কেবল যাঁর হাতে মানুষ সামলানোর চাবি, বাকিদের আংশিক ঢাকা (কন্ট্রোল প্যানেলের পরিকল্পনা, মালিকের
 * অনুমোদিত, ২১ অক্টোবর ২০২৬, দফা ১২: "ড্যাশবোর্ডে ইমেইল খোলা")।
 *
 * ⓘ আংশিক, পুরো নয়: "ow•••@abos.test" দেখে মানুষটা চেনা যায় (কোন ঠিকানা, কোন ডোমেইন), কিন্তু লিখে নেওয়া যায় না।
 * ⓘ চাবিটা `system_admin.user.manage` — ঐ চাবিওয়ালা ব্যবহারকারীর পাতাতেই পুরোটা দেখেন, তাই অন্য পাতায় ঢাকা তাঁর থেকে কিছু লুকায় না।
 * ⛔ সিস্টেমের হোম খোলে সেটিংসের চাবিতে, রোলের পাতা রোলের চাবিতে — দুইটাই মানুষের ঠিকানার চাবি নয়।
 */
final class ContactMask
{
    public const PERMISSION = 'system_admin.user.manage';

    public static function email(?string $email): string
    {
        if ($email === null || $email === '' || self::mayRead()) {
            return (string) $email;
        }

        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).'•••'.($domain !== '' ? '@'.$domain : '');
    }

    public static function phone(?string $phone): string
    {
        if ($phone === null || $phone === '' || self::mayRead()) {
            return (string) $phone;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return strlen($digits) <= 6 ? '•••' : substr($digits, 0, 3).str_repeat('•', strlen($digits) - 6).substr($digits, -3);
    }

    public static function mayRead(): bool
    {
        return (bool) auth()->user()?->can(self::PERMISSION);
    }
}
