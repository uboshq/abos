<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Http\Request;

/**
 * ⭐ ফোনের দরজার ইনপুট — দুই নিয়ম এক জায়গায় (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️১১: "1e3" `numeric` পার হয়ে bcmath-এ
 * ValueError দিত, আর `?q[]=x` দিলে `(string)` অ্যারে — দুটোই ৫০০; জমার অঙ্কের উচ্চসীমাও ছিল না)।
 */
final class PhoneInput
{
    /**
     * এক দশমিক নিয়ম — ১৪ অঙ্ক পর্যন্ত, ৪ দশমিক পর্যন্ত, ঋণাত্মক নয়, বৈজ্ঞানিক লেখা নয়। ⓘ `numeric`-এর পাশে বসে, যাতে
     * `gt`/`min`/`max` সংখ্যা হিসেবেই মাপে (ওগুলো `numeric` ছাড়া লেখার দৈর্ঘ্য মাপে)।
     */
    public const DECIMAL = 'regex:/^\d{1,14}(\.\d{1,4})?$/';

    /** প্রশ্নের একটা ঘর লেখা হিসেবে — অ্যারে বা অন্য কিছু এলে [$default] (৫০০ নয়) */
    public static function text(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }
}
