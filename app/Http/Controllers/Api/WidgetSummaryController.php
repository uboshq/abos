<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Services\PhoneModules;
use App\Core\Widgets\WidgetSummaries;
use App\Core\Widgets\WidgetSummary;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ⭐ ফোনের হোম-স্ক্রিন উইজেটের এক দরজা — `GET /api/v1/widgets/summary?keys=a,b` (মোবাইলের পরিকল্পনা ৪, অংশ ৯;
 * মালিকের আদেশ, ২০ অক্টোবর ২০২৬)।
 *
 * ⭐ এক ডাকে সব উইজেটের সারাংশ — ফোন প্রতি ৩০ মিনিটে আর সার্ভারের নীরব সংকেতে একবার ডাকে, ২০টা দরজায় ২০ বার নয়।
 *
 * ⛔ চাবি নেই মানে ঘরটাই নেই ([[DashboardTodayController]]-এর নিয়ম ক): যার অনুমতি নেই বা যার মডিউল ফোনে বন্ধ, তার
 * চাবি উত্তরেই থাকে না — `null` বা খালি নয়। ফোন তখন সেই উইজেট দেখায় না।
 *
 * ⭐ etag: উত্তর আগের মতো হলে 304, শরীর ছাড়া — ব্যাটারি আর ডেটা বাঁচে। ⓘ etag-এ সময় (`asOf`) ধরা হয় না, কেবল তথ্য।
 *
 * ⓘ কেবল পড়া; কিছু লেখে না।
 */
class WidgetSummaryController extends Controller
{
    public function __invoke(Request $request, PhoneModules $phone): JsonResponse|Response
    {
        /** @var User $user */
        $user = $request->user();

        $asked = array_filter(array_map('trim', explode(',', (string) $request->query('keys', ''))));
        $widgets = [];
        foreach (WidgetSummaries::all() as $key => $summary) {
            /** @var WidgetSummary $summary */
            if ($asked !== [] && ! in_array($key, $asked, true)) {
                continue;
            }
            $permission = $summary->permission();
            if (! $phone->isOn($summary->module()) || ($permission !== null && ! $user->can($permission))) {
                continue;
            }
            $widgets[$key] = $summary->data($user);
        }

        $etag = '"'.sha1((string) json_encode($widgets)).'"';
        if ($request->header('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        return response()
            ->json(['asOf' => now()->toIso8601String(), 'widgets' => (object) $widgets])
            ->header('ETag', $etag);
    }
}
