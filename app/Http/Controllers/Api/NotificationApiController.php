<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Services\NotificationAudit;
use App\Core\Services\NotificationService;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ⭐ ফোনের ঘণ্টা — মালিক, ৬ অক্টোবর ২০২৬: *"User photo pase Notification icon dibe ekta, zate notification gulo dekha zay"*।
 *
 * ⓘ ওয়েবের ঘণ্টার একই খবর ([[NotificationController]]): এই মানুষটার নিজের, এই কোম্পানির, নতুনটা আগে। "পড়া" দাগ দেয়
 * [[NotificationService::markRead()]] — মালিকানা যাচাই সেখানেই, তাই অন্যের খবর খালি করা যায় না (৪০৪)।
 *
 * ⓘ চাবি নেই, নোটিশের দরজাগুলোর মতো — নিজের খবর পড়তে কর্মীর চাবি লাগে না। নম্বর বাইরে যায় না, কেবল `public_id`।
 */
class NotificationApiController extends Controller
{
    /** কত খবর একবারে — ঘণ্টার তালিকা ছোট, পুরনোগুলো ওয়েবে */
    private const LIMIT = 50;

    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // ⭐ ধাপ ১: নাগালের শাখার খবরই, আর্কাইভ করা বাদ ([[NotificationService::mine()]]); `?archived=1` দিলে কেবল আর্কাইভ
        $archived = $request->boolean('archived');
        $rows = $this->notifications->mine($user)
            ->when($archived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->latest('id')->limit(self::LIMIT)->get();

        return response()->json([
            'data' => $rows->map(fn (Notification $n) => [
                'id' => (string) $n->public_id,
                'type' => (string) $n->type,
                'title' => (string) $n->title,
                'body' => (string) ($n->body ?? ''),
                'url' => $n->url,
                'read' => ! $n->isUnread(),
                'at' => $n->created_at?->toIso8601String(),
                'priority' => (string) $n->priority,
                'category' => (string) $n->category,
                'module' => $n->module,
                'archived' => $n->isArchived(),
            ])->values(),
            'meta' => ['unread' => $this->notifications->unreadCount($user)],
        ]);
    }

    /** ⭐ না-পড়া গোনা — ফোনের ব্যাজ (স্পেক §১২ `unread-count`) */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $this->notifications->unreadCount($request->user())]]);
    }

    /** ⭐ আর্কাইভে বা ফেরত (স্পেক §১২ `archive`) — নিজের খবরই, অন্যেরটায় ৪০৪ */
    public function archive(Request $request, string $notification): JsonResponse
    {
        $user = $request->user();
        $row = Notification::query()->wherePublicId($notification)->firstOrFail();

        abort_unless($row->user_id === $user->id, 404);

        $action = $row->isArchived() ? 'restore' : 'archive';
        $this->notifications->act($user, [(int) $row->id], $action);
        app(NotificationAudit::class)->record($action, $row);

        return response()->json(['data' => ['archived' => $action === 'archive']]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $row = Notification::query()->wherePublicId($notification)->firstOrFail();

        abort_unless($this->notifications->markRead($row, $request->user()), 404);

        return response()->json(['data' => ['read' => true]]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->notifications->markAllRead($request->user());

        return response()->json(['data' => ['unread' => 0]]);
    }
}
