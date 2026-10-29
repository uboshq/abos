<?php

declare(strict_types=1);

namespace App\Modules\Notification\Widgets;

use App\Core\Services\NotificationService;
use App\Core\Widgets\WidgetSummary;
use App\Models\Notification;
use App\Models\User;

/**
 * ⭐ "নোটিশ" উইজেট (৭ নম্বর, ৪×২) — ফোনের ঘণ্টার একই খবর, নতুনটা আগে, নীল বিন্দু = না-পড়া; চাপলে সেই কাগজ (a6,
 * ২০ অক্টোবর ২০২৬; [[WidgetSummary]])।
 *
 * ⭐ উৎস একটাই — ঘণ্টার তালিকা ([[NotificationApiController::index()]]): [[NotificationService::mine()]] (নিজের, এই কোম্পানির,
 * নাগালের শাখার), আর্কাইভ বাদ, `id` ধরে নতুনটা আগে। শিরোনাম আর দ্বিতীয় লাইন ফোনের তালিকার হুবহু (title, body) — গ্রাহকের
 * নাম যেমন খবরে লেখা, তেমনই; এখানে নতুন করে কিছু বানানো নয়।
 *
 * ⛔ অন্যের খবর আসে না — ছাঁকনি mine()-এর, আলাদা করে লেখা নয়। ⛔ ডিপ-লিংকের তালিকার বাইরের পথ (লগইন, যাচাইয়ের QR,
 * অন্য সাইট) `link` হয় না — ফোন সেটা খুলতে পারত না, বা ব্রাউজারে পাঠাত।
 *
 * ⓘ `more` = ঘণ্টার একই তালিকায় দেখানো ৮টার বাইরে আর কয়টা (পড়া-না-পড়া মিলিয়ে, আর্কাইভ বাদ)।
 * ⓘ চাবি নেই (null) — নিজের খবর পড়তে কর্মীর চাবি লাগে না, ঘণ্টার দরজার মতোই (routes/api.php-র `/notifications`)।
 * ⓘ কেবল পড়া; "পড়া" দাগ দেয় না — সেটা ফোনের তালিকায় খোলার সময়।
 */
final class NoticesSummary implements WidgetSummary
{
    /** সর্বোচ্চ সারি — দলের চুক্তি (বড় মাপে ৮টা) */
    public const ROWS = 8;

    /**
     * ফোন যে ওয়েবের উপসর্গ অ্যাপে খোলে — অ্যাপের `lib/core/router/deep_links.dart`-এর `DeepLinks.prefixes` হুবহু।
     * ⚠️ ওখানে বদলালে এখানেও।
     */
    public const DEEP_LINK_PREFIXES = [
        '/accounts', '/approvals', '/credit-control', '/customers', '/dashboard', '/documents', '/finance', '/governance',
        '/hr', '/inventory', '/master-data', '/notices', '/notification', '/notifications', '/owner', '/papers',
        '/promotions', '/purchase', '/reports', '/restaurant', '/sales', '/suppliers',
    ];

    public function __construct(private readonly NotificationService $notifications) {}

    public function key(): string
    {
        return 'notices';
    }

    /**
     * ⓘ ঘণ্টা কোনো মডিউলের সুইচে নেই (দরজা কোরে)। তাই অপরিহার্য `system_admin` — ফোনে ডিফল্টে চালু, কোম্পানির মডিউল-সুইচে
     * বন্ধ হয় না; `notification` মডিউল ফোনে ডিফল্টে বন্ধ, ওটা নিলে উইজেট কারও ফোনেই আসত না।
     */
    public function module(): string
    {
        return 'system_admin';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function data(User $user): array
    {
        $inbox = fn () => $this->notifications->mine($user)->whereNull('archived_at');

        $rows = $inbox()->latest('id')->limit(self::ROWS)->get();

        return [
            'unread' => $this->notifications->unreadCount($user),
            'rows' => $rows->map(fn (Notification $n) => [
                'title' => (string) $n->title,
                'second' => ($n->body ?? '') !== '' ? (string) $n->body : null,
                'at' => $n->created_at?->toIso8601String(),
                'unread' => $n->isUnread(),
                'link' => self::link($n->url),
            ])->values()->all(),
            'more' => max(0, $inbox()->count() - $rows->count()),
        ];
    }

    /**
     * খবরের "খুলুন"-এর পথ — অ্যাপের `NotificationRecord.webPath`-এর একই নিয়ম (কেবল পথ আর প্রশ্ন, `/` দিয়ে শুরু), আর
     * ডিপ-লিংকের উপসর্গের ভিতরে হলে তবেই।
     */
    public static function link(?string $url): ?string
    {
        $raw = trim((string) $url);
        if ($raw === '') {
            return null;
        }

        $path = (string) parse_url($raw, PHP_URL_PATH);
        if ($path === '' || ! str_starts_with($path, '/')) {
            return null;
        }

        foreach (self::DEEP_LINK_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                $query = parse_url($raw, PHP_URL_QUERY);

                return is_string($query) && $query !== '' ? $path.'?'.$query : $path;
            }
        }

        return null;
    }
}
