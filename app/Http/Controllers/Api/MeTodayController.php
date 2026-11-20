<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\NotificationService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ⭐ `GET /me/today` — প্রোফাইল পাতার তিন সংখ্যা: আমার অপেক্ষার অনুমোদন, না-পড়া খবর, বন্ধ-না-হওয়া দিন (২০ নভেম্বর ২০২৬)।
 *
 * ⓘ প্রতিটা সংখ্যা তার নিজের জায়গা থেকে, নতুন কোনো হিসাব নয়:
 *   - অনুমোদন — [[ApprovalEngine::pendingQueryFor()]], ইনবক্স আর উপরের ফিতার একই গোনা ([[StatusNotices]])।
 *   - খবর — [[NotificationService::unreadCount()]], ঘণ্টার ব্যাজের একই গোনা।
 *   - দিন — [[DayChecklist::openDays()]], খোলার সময়ের পপআপের একই তালিকা; কেবল দিন বন্ধের চাবি যাঁর (`accounts.day_close.close`)।
 *
 * ⛔ কোনো মডিউল বন্ধ বা ভাঙা থাকলে সেই সংখ্যা ০ — পাতাটা কখনো ৫০০ দেয় না (প্রতিটা আলাদা try/catch, ঘটনা লগে যায়)।
 */
final class MeTodayController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403, 'staff-token-only');

        return response()->json([
            'approvals_waiting' => self::safely(fn (): int => app(ApprovalEngine::class)->pendingQueryFor($user)->count()),
            'unread_notices' => self::safely(fn (): int => app(NotificationService::class)->unreadCount($user)),
            'day_close_pending' => self::safely(function () use ($user): int {
                $checklist = \App\Modules\Accounts\Services\DayChecklist::class;

                if (! class_exists($checklist) || ! $user->can('accounts.day_close.close')) {
                    return 0;
                }

                return count(app($checklist)->openDays(now()->startOfDay()));
            }),
        ]);
    }

    /** @param  callable(): int  $count */
    private static function safely(callable $count): int
    {
        try {
            return max(0, (int) $count());
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }
}
