<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Engines\Sync\SyncRegistry;
use App\Core\Engines\Sync\SyncService;
use App\Http\Controllers\Controller;
use App\Models\SyncConflict;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ফোনের সাথে সার্ভারের একমাত্র কথোপকথন — `/api/v1/sync/**`।
 *
 * ── কেন প্রতিটা দরজায় `deviceId` ─────────────────────────────────────
 * সিঙ্কের সব হিসাব ডিভাইস ধরে, ব্যবহারকারী ধরে নয়। একজনের দুইটা ফোন
 * থাকলে ব্যবহারকারী ধরে হিসাব রাখলে একটা ফোন অন্যটার ওয়াটারমার্ক
 * এগিয়ে দিত, আর দ্বিতীয় ফোনটা এমন রেকর্ড কোনোদিন পেত না যা প্রথমটা
 * ইতিমধ্যে নামিয়ে ফেলেছে।
 *
 * ── কেন এখানে কোনো ব্যবসার নিয়ম নেই ─────────────────────────────────
 * কন্ট্রোলার কেবল যাচাই করে আর ডাকে (সেকশন ১৯.১)। অর্ডারটা কীভাবে বসে
 * সেটা মডিউলের সার্ভিসের কাজ, আর সেটাই থাকে — নাহলে ওয়েব আর ফোন দুইটা
 * আলাদা উত্তর দিত।
 */
class SyncController extends Controller
{
    public function __construct(
        private readonly SyncService $sync,
        private readonly SyncRegistry $registry,
    ) {}

    /**
     * `GET /sync/capabilities` — এই সার্ভার কী সিঙ্ক করতে পারে।
     *
     * ফোন এখান থেকেই নিজের পরিকল্পনা বানায়, হাতে লেখা তালিকা থেকে নয়।
     * তাই সার্ভারে একটা হ্যান্ডলার যোগ বা বাদ দিলে **নতুন মোবাইল রিলিজ
     * ছাড়াই** সেটা কার্যকর হয় — আর সেটাই দরকার, কারণ কোন জিনিস অফলাইনে
     * যাবে সেটা ব্যবসার সিদ্ধান্ত, আর ব্যবসার সিদ্ধান্ত বদলায়।
     */
    public function capabilities(): JsonResponse
    {
        return response()->json($this->registry->capabilities());
    }

    /**
     * `POST /sync/{module}/push` — ফোনের কিউ থেকে যা এসেছে।
     *
     * ── কেন ২০০, প্রত্যাখ্যানের পরেও ────────────────────────────────
     * একটা ব্যাচে পঞ্চাশটা বদল থাকতে পারে, আর তার কিছু বসবে কিছু বসবে
     * না। HTTP-র একটামাত্র স্ট্যাটাস দিয়ে সেটা বলা যায় না।
     *
     * তাই অনুরোধটা সফল (সার্ভার শুনেছে ও উত্তর দিয়েছে), আর **প্রতিটা
     * বদলের নিজের পরিণতি শরীরের ভেতরে**। ফোন `changeId` ধরে মিলিয়ে
     * নেয়, ক্রম ধরে নয়।
     */
    public function push(Request $request, string $module): JsonResponse
    {
        $user = $this->staff($request);
        $deviceId = $this->deviceId($request);

        if (! $this->registry->knowsModule($module)) {
            return response()->json([
                'message' => __('sync.module_unknown', ['module' => $module]),
            ], 404);
        }

        $this->sync->register($user, $deviceId, $request->header('X-App-Version'), $request->header('X-Platform'));

        /*
         * শরীরটা একটা তালিকা, বস্তু নয় — `sync_engine.dart` সরাসরি
         * `changes` অ্যারেটাই পাঠায়। `$request->all()` তালিকাটাকেই
         * ফেরত দেয়, তাই আলাদা কোনো মোড়ক নেই।
         */
        $rows = $request->all();

        if (! array_is_list($rows)) {
            return response()->json([
                'message' => __('sync.change_needs_payload'),
            ], 422);
        }

        return response()->json([
            'outcomes' => $this->sync->push($user, $deviceId, $module, $rows),
        ]);
    }

    /**
     * `GET /sync/{module}/pull` — এই ডিভাইস যা এখনো পায়নি।
     *
     * ── কেন `since` নেওয়া হয় না ─────────────────────────────────────
     * ওয়াটারমার্ক সার্ভার রাখে। তাই পরপর দুইবার ডাকলে হুবহু একই ব্যাচ
     * ফেরে — আর সেটাই একটা হারিয়ে যাওয়া উত্তরের পর আবার চেষ্টা করাকে
     * নিরাপদ করে। ফোনের ঘড়ির উপরও ভরসা করতে হয় না, যেটা ভুল হলে
     * রেকর্ড **নীরবে** বাদ পড়ত।
     */
    public function pull(Request $request, string $module): JsonResponse
    {
        $user = $this->staff($request);
        $deviceId = $this->deviceId($request);

        if (! $this->registry->knowsModule($module)) {
            return response()->json([
                'message' => __('sync.module_unknown', ['module' => $module]),
            ], 404);
        }

        $this->sync->register($user, $deviceId, $request->header('X-App-Version'), $request->header('X-Platform'));

        /*
         * ১ থেকে ১০০০ — উপরের সীমাটা সার্ভারের, ফোনের চাওয়া নয়। একটা
         * ফোন `limit=100000` চাইলে সার্ভারের স্মৃতি ফুরাত, আর সেটা
         * ফোনের নয় সার্ভারের সমস্যা হত।
         */
        $limit = max(1, min(1000, (int) $request->query('limit', '500')));

        return response()->json($this->sync->pull($user, $deviceId, $module, $limit));
    }

    /**
     * `POST /sync/{module}/pull-complete` — "পুরোটা পেয়েছি"।
     *
     * ── ⚠️ ফোনকে বিশ্বাস করা হচ্ছে, আর সেটা জেনেবুঝে ────────────────
     * সার্ভার জানে না ফোন সত্যিই সব লিখতে পেরেছে কি না। কিন্তু
     * বিকল্পটা আরও খারাপ: সার্ভার নিজে থেকে ওয়াটারমার্ক এগিয়ে দিলে
     * একটা হারিয়ে যাওয়া উত্তরের পর রেকর্ডগুলো **চিরতরে** বাদ পড়ত।
     *
     * ভুল করে না-ডাকার শাস্তি কেবল একই ডেটা আবার নামা। ভুল করে
     * ডাকার শাস্তি হারানো ডেটা। তাই সিদ্ধান্তটা ফোনের হাতে, আর নিয়মটা
     * দুই পাশেই লেখা।
     */
    public function pullComplete(Request $request, string $module): JsonResponse
    {
        $this->staff($request);
        $deviceId = $this->deviceId($request);

        if (! $this->registry->knowsModule($module)) {
            return response()->json([
                'message' => __('sync.module_unknown', ['module' => $module]),
            ], 404);
        }

        $this->sync->recordSuccessfulPull($deviceId, $module);

        return response()->json(['ok' => true]);
    }

    /**
     * `GET /sync/{module}/last-sync` — এই ডিভাইস শেষ কবে পেয়েছে।
     *
     * সহায়তার জন্য: "আমার তালিকা পুরনো" অভিযোগ এলে প্রথম প্রশ্নটাই এটা।
     */
    public function lastSync(Request $request, string $module): JsonResponse
    {
        $this->staff($request);
        $deviceId = $this->deviceId($request);

        $at = $this->sync->lastSync($deviceId, $module);

        return response()->json([
            'lastSyncedAt' => $at?->toIso8601String(),
        ]);
    }

    /**
     * `GET /sync/conflicts` — যা নিয়ে মানুষ সিদ্ধান্ত নেবেন।
     *
     * ⚠️ **অডিট-স্তরের অনুমতি, মডিউলের নয়** — সারিটায় ফোনের রূপ আর
     * সার্ভারের রূপ **দুইটাই** আছে, অর্থাৎ ওটা দুই পাশের যোগফলের চেয়ে
     * বেশি গোপন। যিনি অর্ডার দেখতে পান তিনি এটা দেখতে পাওয়ার কথা নয়।
     */
    public function conflicts(Request $request): JsonResponse
    {
        $this->staff($request);

        $rows = array_map(fn (SyncConflict $conflict) => [
            'id' => $conflict->public_id,
            'module' => $conflict->module,
            'entityType' => $conflict->entity_type,
            'entityId' => $conflict->entity_id,
            'reason' => $conflict->reason,
            'status' => $conflict->status,
            'detectedAt' => $conflict->detected_at?->toIso8601String(),
        ], $this->sync->conflicts());

        return response()->json($rows);
    }

    public function resolveConflict(Request $request, string $conflict): JsonResponse
    {
        $user = $this->staff($request);

        $row = SyncConflict::query()->where('public_id', $conflict)->firstOrFail();

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->sync->resolveConflict($row, $user, $validated['note'] ?? null);

        return response()->json(['ok' => true]);
    }

    /**
     * কর্মী, ডিলার নয়।
     *
     * `auth:sanctum` টোকেনধারীকে ঢুকতে দেয়; এটা নিশ্চিত করে তিনি একজন
     * `User` — কারণ [[SyncService]] আর হ্যান্ডলারগুলো `User`-এর জিনিস
     * ধরে নিয়েছে (`current_company_id`, রোল)। ডিলারের পোর্টাল আলাদা
     * গার্ডে চলে, আর সেই দিকটা এখনো টোকেন পায় না।
     */
    private function staff(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * প্রতিটা সিঙ্ক কলে `deviceId` — না থাকলে কিছুই করা যায় না।
     *
     * ৪২২ নয়, ৪০০: এটা ব্যবহারকারীর ভুল নয়, অ্যাপের। কোনো মানুষ এই
     * ঘরটা টাইপ করেন না।
     */
    private function deviceId(Request $request): string
    {
        $deviceId = trim((string) $request->query('deviceId', ''));

        abort_if($deviceId === '', 400, __('sync.device_unknown'));

        return $deviceId;
    }
}
