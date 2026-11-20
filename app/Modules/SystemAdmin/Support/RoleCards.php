<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Support;

/**
 * ⭐ রোলের পাতার পাঁচটা চিকন কার্ডের চিহ্ন আর দুই রং — মালিকের অনুমোদিত নমুনা, ২০ নভেম্বর ২০২৬ ("ok")।
 *
 * ⓘ অন্য তালিকার কার্ডের মতোই ([[CustomerListFilters]]-এর `ListCard`) রং আর চিহ্ন PHP-তে থাকে, পাতায় নয়: পাতা কেবল নাম
 *   ধরে ডাকে, তাই থিমের পাহারা ([[EveryScreenObeysTheThemeTest]]) আর ইমোজির পাহারা ([[IconSetTest]]) পাতাটাকে পরিষ্কার পায়।
 * ⓘ রং দুইটা গাঢ় থেকে উজ্জ্বল — `--a`/`--b`, যেমন `list-card` চায়।
 */
final class RoleCards
{
    /** @var array<string, array{icon: string, tone: array{0: string, 1: string}}> */
    public const LOOK = [
        'users' => ['icon' => '👥', 'tone' => ['#2346d8', '#3b82f6']],
        'roles' => ['icon' => '🔑', 'tone' => ['#059669', '#10b981']],
        'unassigned' => ['icon' => '∅', 'tone' => ['#dc2626', '#f97316']],
        'clash' => ['icon' => '⚠', 'tone' => ['#d97706', '#f59e0b']],
        'last-change' => ['icon' => '🕒', 'tone' => ['#0284c7', '#22d3ee']],
    ];

    /** @return array{0: string, 1: string} */
    public static function tone(string $key): array
    {
        return self::LOOK[$key]['tone'];
    }

    public static function icon(string $key): string
    {
        return self::LOOK[$key]['icon'];
    }
}
