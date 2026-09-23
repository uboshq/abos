<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Dashboard;

use App\Core\Contracts\DashboardWidgets;
use App\Core\Services\NoticeAcknowledgement;
use App\Core\Services\NoticeBoard;
use App\Core\Support\NoticeStatus;
use App\Core\Support\Widget;
use App\Models\Notice;
use App\Models\User;

/**
 * হোম পর্দায় নোটিশের সংখ্যা — স্পেকের ধারা ৫।
 *
 * ── ⚠️ কেন কেবল দুইটা সংখ্যা, দশটা নয় ───────────────────────────────
 * ⓘ স্পেকে দশটা KPI কার্ডের কথা লেখা, আর সবগুলোর জায়গা নোটিশের নিজের
 * হিসাবের পর্দায় ([[NoticeAnalytics]])। ⛔ হোম পর্দায় দশটা বসালে
 * বাকি মডিউলের সংখ্যাগুলো চাপা পড়ত, আর হোম পর্দাটা একটা রিপোর্ট হয়ে
 * যেত।
 *
 * ⭐ এখানে কেবল সেগুলোই যেগুলো **আজকের কাজ**: কী পড়া বাকি, আর কী সই
 * করা বাকি।
 */
final class NoticeWidgets implements DashboardWidgets
{
    /** @return list<Widget> */
    public static function widgets(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $unread = app(NoticeBoard::class)->unreadCount($user);

        /*
         * ⓘ সই বাকি — কেবল **এই মানুষটার**, গোটা কোম্পানির নয়।
         *
         * ⚠️ কোম্পানির সংখ্যাটা হোম পর্দায় বসালে যিনি সই দেওয়ার কেউ
         * নন তিনিও দেখতেন কতজন দেয়নি, ⛔ আর নোটিশের পর্দা একটা
         * নজরদারির পর্দা হয়ে যেত।
         */
        $standing = app(NoticeAcknowledgement::class);

        $owed = app(NoticeBoard::class)->forUser($user)
            ->filter(fn (Notice $notice) => (bool) $notice->ack_required
                && $standing->standingOf($notice, $user)->stillOwes())
            ->count();

        return [
            new Widget(
                group: 'todo',
                label: __('core.notice.unread_widget'),
                value: (string) $unread,
                href: route('system_admin.notice.index'),

                /*
                 * ⚠️ চাবিটা বাধ্যতামূলক, আর এটা মেপে শেখা।
                 *
                 * ⛔ না দিলে [[Widget]]-এর গঠনকারক ব্যতিক্রম ছুঁড়ত, আর
                 * **গোটা হোম পর্দাটা ৫০০** দিত — ধরা পড়েছে
                 * [[EveryMenuPageOpensBeforeItIsDeployedTest]]-এ, যে পাহারাটা
                 * সত্যিকারের পাতা আঁকে।
                 *
                 * ⓘ সোর্স-পড়া পাহারা এটা কখনো ধরত না।
                 */
                permission: 'system_admin.notice.manage',

                tone: $unread > 0 ? 'warn' : 'neutral',
                sort: 7,
                icon: 'bell',
            ),

            new Widget(
                group: 'todo',
                label: __('core.notice.to_sign_widget'),
                value: (string) $owed,
                href: route('system_admin.notice.index'),
                permission: 'system_admin.notice.manage',

                /*
                 * ⚠️ সই বাকি থাকা কেবল একটা খবর নয়, একটা **দায়** —
                 * ⓘ তাই শূন্য না হলে রংটা সতর্ক, নিরপেক্ষ নয়।
                 */
                tone: $owed > 0 ? 'warn' : 'neutral',
                sort: 8,
                icon: 'check_circle',
            ),
        ];
    }
}
