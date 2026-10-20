<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ⭐ ফোনের টাকার দরজার উত্তর — ETag আর ৩০৪, কিন্তু কোনো ক্যাশে নয় (cb, ২০ অক্টোবর ২০২৬; উইজেটের দরজার নিয়মটা এক জায়গায়)।
 *
 * ⓘ ফোন নিজে আগের ETag রাখে আর `If-None-Match` পাঠায়; তথ্য না বদলালে ৩০৪, শরীর ছাড়া — ব্যাটারি আর ডেটা বাঁচে।
 * ⛔ তবু `no-store, private` — উত্তরে হাতের নগদ, দেনা, কমিশন, গ্রাহকের নাম; মাঝের proxy বা ফোনের HTTP ক্যাশে থাকা চলে না
 * (উইজেটের নিরাপত্তা-পর্যালোচনা ৮, MASVS-NETWORK/PRIVACY)। ETag-এর জন্য ক্যাশ লাগে না।
 * ⛔ ETag কেবল **তথ্যের** hash — সময়ের ছাপ (`asOf`) বাইরে রাখতে হয়, নইলে কখনো ৩০৪ আসত না।
 *
 * দরজা তিনটা: উইজেটের সারাংশ ([[WidgetSummaryController]]), আজকের পাতা ([[DashboardTodayController]]) আর প্রিন্সিপালের
 * কমিশনের পর্দা ([[PrincipalCommissionApiController]]) — একই সহায়ক, তাই তিনটা কখনো আলাদা নিয়ম বলে না।
 */
final class UnchangedAnswer
{
    public const NO_STORE = 'no-store, private';

    /**
     * @param  mixed  $facts  যা hash হয় — উত্তরের তথ্য, সময় ছাড়া
     * @param  array<string, mixed>|object  $body  যা পাঠানো হয়
     */
    public static function json(Request $request, mixed $facts, array|object $body): JsonResponse|Response
    {
        $etag = '"'.sha1((string) json_encode($facts)).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag)->header('Cache-Control', self::NO_STORE);
        }

        return response()->json($body)->header('ETag', $etag)->header('Cache-Control', self::NO_STORE);
    }
}
