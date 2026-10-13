<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

/**
 * ⛔ সাধারণ দশমিক লেখা — বৈজ্ঞানিক লেখা (`1e5`) নয়; ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা।
 *
 * ⓘ `numeric` নিয়ম `is_numeric` ধরে, আর সে `1e5` পাশ করায় — অথচ PHP 8-এর bcmath ওটা পড়তে না পেরে ValueError ছোড়ে, পর্দায়
 * ৫০০। তাই `numeric`-এর পাশে এটা বসে, আর মানুষ ঘরের পাশে যাচাইয়ের বার্তা পান ([[RouteVisitService::setTarget()]]-এর একই কথা)।
 * ⓘ ফোনের [[PhoneInput::DECIMAL]] ৪ দশমিকে বাঁধা; এটা কেবল লেখার ধরন দেখে — bcmath যা পড়ে তার সবটা (চিহ্ন, `.5`, `5.`)।
 */
final class PlainNumber
{
    public const RULE = 'regex:/^[+-]?(\d+(\.\d*)?|\.\d+)$/';

    /** সেবার পথের একই প্রশ্ন — ইমপোর্ট বা অন্য দরজা থেকে আসা লেখা। */
    public static function is(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', (string) $value) === 1;
    }
}
