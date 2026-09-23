<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Services\NoticeAcknowledgement;
use App\Core\Services\NoticeAudience;
use App\Core\Services\NoticeBoard;
use App\Http\Controllers\Controller;
use App\Models\Notice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ফোনের জন্য নোটিশ — পড়া, মেনে নেওয়া, আর বারের তালিকা।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ৩৩ ────────────────────
 * *"API versioning mandatory: /api/v1/"*।
 *
 * ── ⚠️ কেন এখানে লেখা বা অনুমোদনের দরজা নেই ─────────────────────────
 * ⓘ ফোনে নোটিশ **পড়া** হয়, লেখা হয় না। ⛔ লেখার দরজা খুললে ছাঁকনি
 * আর অনুমোদনের গোটা নিয়মটা দ্বিতীয়বার লিখতে হত — আর দুইবার লেখা
 * নিয়ম একদিন দুইরকম হয়।
 *
 * ⓘ বাইরের কেউ `id` দেখে না, `public_id` দেখে ([[HasPublicId]]) —
 * ⚠️ ক্রমিক সংখ্যা গোনা যায়, আর গোনা গেলে *"আমার আগে কয়টা নোটিশ
 * ছিল"* প্রশ্নের উত্তরও পাওয়া যায়।
 */
final class NoticeApiController extends Controller
{
    /**
     * ⭐ এই মানুষটার নোটিশগুলো — আর কোনটার সাথে তিনি কোথায় দাঁড়িয়ে।
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $standing = app(NoticeAcknowledgement::class);

        $notices = app(NoticeBoard::class)->forUser($user)->map(fn (Notice $notice) => [
            'id' => $notice->public_id,
            'no' => $notice->document_no,
            'title' => $notice->title,
            'summary' => $notice->summary,
            'body' => $notice->body,
            'priority' => $notice->priority?->value,
            'published_at' => $notice->published_at?->toIso8601String(),
            'expires_at' => $notice->expires_at?->toIso8601String(),
            'ack_required' => (bool) $notice->ack_required,
            'standing' => $standing->standingOf($notice, $user)->value,
        ])->values();

        return response()->json(['data' => $notices]);
    }

    /** ⓘ বারে যা যাবে — অগ্রাধিকার ধরে, সীমা মেনে। */
    public function bar(Request $request): JsonResponse
    {
        $notices = app(NoticeBoard::class)->forTicker($request->user())
            ->map(fn (Notice $notice) => [
                'id' => $notice->public_id,
                'title' => $notice->title,
                'priority' => $notice->priority?->value,
                'can_dismiss' => $notice->priority?->canBeDismissed() ?? true,
            ])->values();

        return response()->json(['data' => $notices]);
    }

    /** পড়া হয়েছে বলে দাগ। */
    public function read(Request $request, string $notice): JsonResponse
    {
        $entry = $this->mine($request, $notice);

        app(NoticeBoard::class)->markRead($entry, $request->user());

        return response()->json(['data' => ['standing' => 'read']]);
    }

    /**
     * ⭐ সই দেওয়া — পড়া নয়।
     *
     * ⓘ IP আর যন্ত্রের নাম সারিতে বসে, কারণ ছয় মাস পরে *"আমি ওটা
     * মানিনি"* বলা হলে সারিটাই একমাত্র উত্তর।
     */
    public function acknowledge(Request $request, string $notice): JsonResponse
    {
        $entry = $this->mine($request, $notice);

        app(NoticeAcknowledgement::class)->sign($entry, $request->user(), $request);

        return response()->json(['data' => ['standing' => 'acknowledged']]);
    }

    /**
     * এই মানুষটার নাগালের ভিতরের নোটিশ — নইলে ৪০৪।
     *
     * ── ⚠️ কেন ৪০৪, ৪০৩ নয় ──────────────────────────────────────────
     * ⓘ ৪০৩ বলে *"আছে, কিন্তু তোমার নয়"* — আর ওটুকুই যথেষ্ট খবর:
     * ⛔ নম্বর ধরে ধরে ডাকলে কোন নোটিশগুলো সত্যিই আছে তা গোনা যেত।
     */
    private function mine(Request $request, string $publicId): Notice
    {
        $notice = Notice::query()->wherePublicId($publicId)->firstOrFail();

        abort_unless(app(NoticeAudience::class)->reaches($notice, $request->user()), 404);

        return $notice;
    }
}
