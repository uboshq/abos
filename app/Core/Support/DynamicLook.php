<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * "Dynamic" রূপ চালু কি না — শেলের যে কয়েকটা জায়গা এই রূপে অন্য markup আঁকে, তারা এই একটা প্রশ্নই করে।
 *
 * ⭐ মালিক, ১৯ নভেম্বর ২০২৬ ("ok final"): Dynamic রূপ একটা পরিচিত অফিস-ERP-র (Business Central) চেহারা হুবহু নেবে —
 * কালো অ্যাপ-বার, তার নিচে কোম্পানির নাম আর আড়াআড়ি মেনু, বাঁয়ে কোনো রেল নেই, হোমে "রোল সেন্টার"।
 *
 * ⓘ প্রশ্নটা লেআউটের `data-ui` যেভাবে ঠিক হয় হুবহু সেভাবেই ([[LookRegistry::lookFor()]] + [[LookPreview::orChosen()]]) —
 * তাই যে পাতায় স্টাইলশিট `[data-ui='dynamic']` ধরে, সেই পাতাতেই এই markup-ও আসে; দুইটা কখনো আলাদা কথা বলে না।
 *
 * ⛔ এটা কেবল **আঁকা** বদলায়। অনুমতি, মেনুর সারি, ঠিকানা — সব আগের [[\App\Core\Services\MenuBuilder]] থেকেই।
 */
final class DynamicLook
{
    public const KEY = 'dynamic';

    public static function on(): bool
    {
        return LookRegistry::lookFor(LookPreview::orChosen(auth()->user()?->ui)) === self::KEY;
    }

    /**
     * হোমের নিচের টিয়া লিংকের সারি — বেশি খোলা পাতাগুলো, এই ক্রমে।
     *
     * ⓘ কেবল রুটের নাম; লেবেল, ঠিকানা আর অনুমতি মেনু থেকে আসে — মেনুতে যা নেই (চাবি নেই, মডিউল বন্ধ) তা এখানেও নেই।
     *
     * @var list<string>
     */
    public const LINKS = [
        'customer.index',
        'supplier.index',
        'inventory.product.index',
        'sales.order.index',
        'accounts.voucher.index',
        'accounts.coa.index',
        'accounts.daybook',
    ];

    /**
     * হোমের "Actions" — শুরু করার কাজ (＋) আর যাওয়ার পাতা (›), দুইটাই মেনুর সারি থেকে।
     *
     * @var list<string>
     */
    public const START = [
        'sales.order.create',
        'sales.direct.create',
        'sales.quotation.create',
        'purchase.direct.create',
        'sales.claim.request.create',
    ];

    /** @var list<string> */
    public const GO = [
        'accounts.voucher.index',
        'accounts.daybook',
        'accounts.control.month_end',
    ];

    /**
     * মেনুর সব সারি, রুটের নাম ধরে — যাদের ঠিকানা আছে কেবল তারা।
     *
     * @param  array<int, array<string, mixed>>  $menu
     * @return array<string, array<string, mixed>>
     */
    public static function itemsByRoute(array $menu): array
    {
        $byRoute = [];

        foreach ($menu as $module) {
            foreach ($module['groups'] ?? [] as $items) {
                foreach ($items as $item) {
                    $route = (string) ($item['route'] ?? '');
                    if ($route !== '' && ($item['url'] ?? null) && ! isset($byRoute[$route])) {
                        $byRoute[$route] = $item;
                    }
                }
            }
        }

        return $byRoute;
    }

    /**
     * কয়েকটা রুটের সারি, ঠিক এই ক্রমে — মেনুতে যেটা নেই সেটা বাদ।
     *
     * @param  array<int, array<string, mixed>>  $menu
     * @param  list<string>  $routes
     * @return list<array<string, mixed>>
     */
    public static function pick(array $menu, array $routes): array
    {
        $byRoute = self::itemsByRoute($menu);

        return array_values(array_filter(array_map(fn (string $r) => $byRoute[$r] ?? null, $routes)));
    }
}
