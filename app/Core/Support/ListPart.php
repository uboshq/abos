<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Http\Request;

/**
 * ⭐ তালিকার কেবল তালিকা-অংশ — খোলস ছাড়া (মালিক, ২৩ নভেম্বর ২০২৬: *খুঁজলে বা পাতা বদলালে পুরো পাতা নতুন করে আসে*)।
 *
 * ⓘ তালিকার খোঁজা, সাজানো, পাতা বদলানো এখন পেছনে একই ঠিকানা আনে, হেডার `X-ABOS-Part: list` সহ
 * ([[list-swap.js]]); উত্তরে কেবল `<main>`-এর ভিতরটা আর যোগফলের স্ট্যাক — মেনু, টপবার, নিচের পট্টি আঁকা হয় না।
 * ব্রাউজার তা থেকে টেবিল, পাতার সারি, গোনা, চিপ আর কার্ড (`data-list-part`) জায়গায় বসায়।
 *
 * ⓘ শাখাটা একবারই, খোলসে ([[layouts.app]]) — ১২৭টা নিয়ন্ত্রকে নয়; পিকের ([[Peek]]) একই ছাঁচ।
 * ⛔ কেবল আঁকা বদলায় — অনুমতি, কোম্পানির ছাঁকনি, স্কোপ কিছুই এই হেডার দেখে না।
 * ⚠️ একই ঠিকানার দুই চেহারা, তাই `Vary` ([[PeekVaries]])।
 */
final class ListPart
{
    public const HEADER = 'X-ABOS-Part';

    public const VALUE = 'list';

    /** টুকরোর গায়ের চিহ্ন — ব্রাউজার কেবল এমন উত্তরই বসায়; না থাকলে (লগইনের পাতা, ত্রুটি) পুরো পাতা খোলে। */
    public const FRAGMENT = 'data-list-part-fragment';

    public static function wanted(?Request $request = null): bool
    {
        $request ??= request();

        // ⛔ কেবল GET — জমার উত্তরে খোলস বাদ গেলে মানুষ মেনুহীন পাতায় আটকাতেন
        if (! $request->isMethod('GET') || $request->expectsJson()) {
            return false;
        }

        return $request->header(self::HEADER) === self::VALUE && ! Peek::wanted($request);
    }
}
