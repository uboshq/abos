<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Api\AppVersionController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐ বন্ধ করা বিল্ড আর লিখতে পারে না — সার্ভারও ফেরায়, কেবল ফোন নয় (পূর্ণ নিরীক্ষা §৯ ⚠️৯, ১৮ অক্টোবর ২০২৬; forced update)।
 *
 * ⛔ আগে "সর্বনিম্ন সংস্করণ" কেবল ফোনের নিজের পরীক্ষা: `/app/version` না পৌঁছালে ফোন ধরে নিত সব ঠিক, আর সার্ভার
 * `X-App-Version` কেবল খাতায় লিখত — বন্ধ করে দেওয়া বিল্ডও অর্ডার, আদায়, সই বসাতে থাকত।
 *
 * ⭐ ফোনের লেখায় (POST/PUT/PATCH/DELETE) বিল্ডের কোড [[AppVersionController::enforcedMinimum()]]-এর কম হলে ৪২৬, আর ফোন তখন
 * আপডেটের দেয়াল তোলে। পড়া খোলা — পুরনো বিল্ডেও নিজের খাতা দেখা যায়, আর দেয়ালটা ঠিক কোথায় নতুনটা সেটা পড়ে।
 *
 * ── কোন বিল্ড কত পুরনো ─────────────────────────────────────────────
 * এই বিল্ড থেকে ফোন `X-App-Code` পাঠায় (ছোট কোড, `minimum_code`-এর একই মাপ — APK-র ২০০xx নয়)। ⓘ এর আগের সব বিল্ড
 * কেবল `X-App-Version` (নাম) পাঠায়, কোড নয় — তাদের কোড [[LAST_CODE_WITHOUT_THE_HEADER]]-এর বেশি হতে পারে না, তাই সর্বনিম্ন
 * তার উপরে উঠলে তবেই তারা ফেরে। ⛔ সার্ভার নামা মাত্র মাঠের ০.৪.৩৫-গুলো আটকায় না — যতক্ষণ না মালিক সর্বনিম্ন তোলেন।
 *
 * ⓘ ফোনের অনুরোধ চেনা যায় তার হেডারে (`X-App-Code`, `X-App-Version` বা `X-Platform`) — ব্রাউজার আর প্রোভাইডারের ফেরত-খবর
 * এখানে কিছুই পায় না। বেরোনো আর ক্র্যাশের খবর সবসময় খোলা — পুরনো বিল্ডও বেরোতে আর ভাঙার খবর দিতে পারে।
 */
final class RefuseRetiredAppBuilds
{
    /** ⓘ `X-App-Code` আসার আগের শেষ বিল্ড — ০.৪.৩৫ (`AppConfig.appVersionCode` = ৪০) */
    public const LAST_CODE_WITHOUT_THE_HEADER = 40;

    /** @var list<string> সবসময় খোলা পথের নাম */
    private const ALWAYS_OPEN = ['api.auth.logout', 'api.app.'];

    private const WRITES = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array(strtoupper($request->method()), self::WRITES, true)) {
            return $next($request);
        }

        $minimum = AppVersionController::enforcedMinimum();
        $code = self::buildCode($request);

        if ($minimum === null || $code === null || $code >= $minimum) {
            return $next($request);
        }

        $name = (string) $request->route()?->getName();
        foreach (self::ALWAYS_OPEN as $open) {
            if ($name === $open || str_starts_with($name, $open)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'অ্যাপের এই সংস্করণ আর চলে না — নতুন সংস্করণ বসিয়ে আবার চেষ্টা করুন।',
            'code' => 'app_update_required',
            'minimumCode' => $minimum,
        ], 426);
    }

    /**
     * বিল্ডের কোড — `X-App-Code` থেকে; না থাকলে কিন্তু ফোনের অনুরোধ হলে পুরনো বিল্ড ([[LAST_CODE_WITHOUT_THE_HEADER]]);
     * ফোনের নয় হলে null (কিছু করার নেই)।
     */
    private static function buildCode(Request $request): ?int
    {
        $raw = trim((string) $request->header('X-App-Code', ''));

        if ($raw !== '') {
            // ⛔ ভুল লেখা কোড পুরনো ধরা হয় — নইলে "abc" পাঠিয়ে যে কেউ দেয়াল পেরোত
            return preg_match('/^[0-9]{1,9}$/', $raw) === 1 ? (int) $raw : 0;
        }

        if ($request->hasHeader('X-App-Version') || $request->hasHeader('X-Platform')) {
            return self::LAST_CODE_WITHOUT_THE_HEADER;
        }

        return null;
    }
}
