<?php

declare(strict_types=1);

namespace App\Core\Widgets;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\PhoneModules;
use App\Models\User;
use InvalidArgumentException;

/**
 * ⭐ ফোনের উইজেটের সারাংশগুলো — প্রতিটা মডিউল নিজের `module.php`-তে `'phone_widgets' => [XSummary::class]` বলে
 * ([[ModuleDefinition::$phoneWidgets]]); এখানে কেবল জড়ো করা। ⛔ কোর মডিউলের নাম জানে না ([[BoundariesTest]]; cb-র ধরা,
 * ২০ অক্টোবর ২০২৬) — তাই এখানে কোনো তালিকা নেই।
 */
final class WidgetSummaries
{
    /** @var list<class-string<WidgetSummary>> কেবল টেস্টে — দরজার দাবি নিজের নকল সারাংশ বসায় */
    public static array $testing = [];

    /** @return array<string, WidgetSummary> চাবি ধরে */
    public static function all(): array
    {
        $out = [];
        $classes = static::$testing;
        foreach (app(ModuleRegistry::class)->all() as $module) {
            array_push($classes, ...$module->phoneWidgets);
        }
        foreach ($classes as $class) {
            $summary = app($class);
            if (isset($out[$summary->key()])) {
                // ⛔ দুই উইজেট একই চাবি — একটা নীরবে আরেকটাকে চাপা দিলে ফোন ভুল উইজেট আঁকত
                throw new InvalidArgumentException("Two phone widgets declare the key '{$summary->key()}'.");
            }
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
