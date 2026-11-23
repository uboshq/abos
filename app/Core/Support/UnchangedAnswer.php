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
 * ⭐ Speed work, 23 Nov 2026: the tag is **weak** (`W/"…"` — the same facts, not the same bytes) and **scoped**: the
 * user, the company, the chosen branch and the branches the user may see are hashed in with the facts, so one user's
 * tag never answers 304 to another user (or the same user in another company or branch) even when the facts match.
 * `If-None-Match` is read the standard way (a list, weak comparison, `*`). The same rule serves the doors that build
 * their own answer (below) and the doors wrapped by [[AnswersUnchanged]].
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
        $etag = self::tag($request, (string) json_encode($facts));

        if (self::matches($request, $etag)) {
            return self::notModified($etag);
        }

        return response()->json($body)->header('ETag', $etag)->header('Cache-Control', self::NO_STORE);
    }

    /** the weak tag of `$facts` for this request's user, company and branch */
    public static function tag(Request $request, string $facts): string
    {
        $user = $request->user();
        $branches = $user === null ? null : app(\App\Core\Services\DataScope::class)->viewBranchIds($user);

        return 'W/"'.sha1(implode('|', [
            $user === null ? '-' : $user::class.':'.$user->getAuthIdentifier(),
            (string) CompanyContext::id(),
            (string) CompanyContext::branchId(),
            $branches === null ? 'all' : implode(',', $branches),
            sha1($facts),
        ])).'"';
    }

    /** `If-None-Match` names this tag — a list, weak comparison (RFC 9110 §13.1.2), or `*` */
    public static function matches(Request $request, string $etag): bool
    {
        $header = trim((string) $request->header('If-None-Match', ''));

        if ($header === '') {
            return false;
        }

        if ($header === '*') {
            return true;
        }

        $opaque = fn (string $tag): string => (string) preg_replace('~^W/~i', '', trim($tag));

        foreach (explode(',', $header) as $candidate) {
            if (trim($candidate) !== '' && $opaque($candidate) === $opaque($etag)) {
                return true;
            }
        }

        return false;
    }

    public static function notModified(string $etag): Response
    {
        return response('', 304)->header('ETag', $etag)->header('Cache-Control', self::NO_STORE);
    }
}
