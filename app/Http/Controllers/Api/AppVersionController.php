<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * "আমি কি পুরনো?" — ফোনের প্রশ্ন, লগইনের আগেই।
 *
 * ⓘ চুক্তি §৬। ⚠️ এই একটাই দরজা যেখানে টোকেন লাগে না: যে বিল্ড লগইনই
 * করতে পারে না, ঠিক তাকেই বলা দরকার সে পুরনো।
 *
 * ── ⛔ ঠিকঠাক বসানো না থাকলে উত্তর 503, "পুরনো" নয় ──────────────────
 * ⓘ ফোন ব্যর্থ পরীক্ষায় কিছুই করে না (চুক্তির নিয়ম ক)। ⚠️ কিন্তু
 * `versionCode: 0` বা আন্দাজি URL দিলে সেটা একটা আসল উত্তরের মতো
 * দেখাত — আর `minimumCode` ভুল হলে মাঠের প্রতিটা ফোনে দেয়াল উঠত,
 * নেট থাকা অবস্থাতেই। তাই অর্ধেক-বসানো মানও "বসানো নেই"।
 */
class AppVersionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $android = (array) config('mobile.android');

        $code = self::whole($android['version_code'] ?? null);
        $minimum = self::whole($android['minimum_code'] ?? null);
        $url = trim((string) ($android['url'] ?? ''));

        /*
         * ⛔ minimumCode > versionCode মানে সবচেয়ে নতুন বিল্ডও "আর চলবে না"
         * — অর্থাৎ কোনো ফোনই বাঁচত না। ⓘ ওটা ভুল বসানো, সিদ্ধান্ত নয়।
         */
        if ($code === null || $minimum === null || $url === '' || $minimum > $code) {
            return response()->json(['configured' => false], 503);
        }

        $body = [
            'versionCode' => $code,
            'versionName' => (string) ($android['version_name'] ?? ''),
            'url' => $url,
            'minimumCode' => $minimum,
        ];

        /*
         * ⚠️ নোট দুই ভাষাতেই, নাহলে নেই (চুক্তির নিয়ম ঘ) — এক ভাষার নোট
         * অন্য ভাষার ফোনে অপঠিত লেখা হয়ে বসত।
         */
        $bn = trim((string) ($android['note']['bn'] ?? ''));
        $en = trim((string) ($android['note']['en'] ?? ''));

        if ($bn !== '' && $en !== '') {
            $body['note'] = ['bn' => $bn, 'en' => $en];
        }

        return response()->json($body);
    }

    /** একটা ধনাত্মক পূর্ণসংখ্যা, নাহলে null — "3a" বা "" মানে বসানো নেই। */
    private static function whole(mixed $value): ?int
    {
        $value = trim((string) $value);

        return preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1 ? (int) $value : null;
    }
}
