<?php

declare(strict_types=1);

namespace App\Core\Widgets;

use App\Core\Services\PhoneModules;
use App\Models\User;

/**
 * ⭐ ফোনের উইজেটের সারাংশগুলোর তালিকা — প্রতিটা উইজেট এখানে এক লাইন ([[WidgetSummary]])।
 *
 * ⓘ একটা তালিকা, পাঁচজন একসাথে লিখবে — প্রত্যেকে নিজের লাইন যোগ করে, মেশানোর সময় a4 মেলায়।
 */
final class WidgetSummaries
{
    /** @var list<class-string<WidgetSummary>> */
    public const CLASSES = [
        \App\Modules\Executive\Widgets\OwnerBoardSummary::class,
    ];

    /** @var list<class-string<WidgetSummary>> কেবল টেস্টে — দরজার দাবি নিজের নকল সারাংশ বসায় */
    public static array $testing = [];

    /** @return array<string, WidgetSummary> চাবি ধরে */
    public static function all(): array
    {
        $out = [];
        foreach ([...static::CLASSES, ...static::$testing] as $class) {
            $summary = app($class);
            $out[$summary->key()] = $summary;
        }

        return $out;
    }

    /**
     * এই মানুষ যে উইজেটগুলো পায় — অনুমতি ([[WidgetSummary::permission()]], null মানে সবাই) আর ফোনের মডিউল-সুইচ ধরে।
     * দরজা আর মালিকের পূর্ণ-পর্দা দুজনেই এই এক ছাঁকনি নেয়, তাই দুজনে কখনো আলাদা কথা বলে না।
     *
     * @return array<string, WidgetSummary>
     */
    public static function visibleTo(User $user): array
    {
        $phone = app(PhoneModules::class);

        return array_filter(static::all(), function (WidgetSummary $summary) use ($phone, $user): bool {
            $permission = $summary->permission();

            return $phone->isOn($summary->module()) && ($permission === null || $user->can($permission));
        });
    }
}
