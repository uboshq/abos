<?php

declare(strict_types=1);

namespace App\Core\Widgets;

/**
 * ⭐ ফোনের উইজেটের সারাংশগুলোর তালিকা — প্রতিটা উইজেট এখানে এক লাইন ([[WidgetSummary]])।
 *
 * ⓘ একটা তালিকা, পাঁচজন একসাথে লিখবে — প্রত্যেকে নিজের লাইন যোগ করে, মেশানোর সময় a4 মেলায়।
 */
final class WidgetSummaries
{
    /** @var list<class-string<WidgetSummary>> */
    public const CLASSES = [
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
}
