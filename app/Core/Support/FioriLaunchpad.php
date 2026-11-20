<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Navigation\MenuLook;

/**
 * ⭐ SAP রূপের লঞ্চপ্যাড — Fiori "Spaces and Pages" (Horizon): উপরের পটিতে স্পেস, স্পেস খুললে তার পাতা, পাতায় ভাগ, ভাগে টালি।
 *
 * ── কেন (মালিক, ২০ নভেম্বর ২০২৬) ─────────────────────────────────────────
 * *"SAP-এর ভেতরে Dynamic-এর চেহারা ঢুকে গেছে"* — SAP রূপে মডিউলের নাম চাপলে মেনুর সব সারির লম্বা ড্রপডাউন খুলত; ওটা Business
 * Central-এর ধরন, SAP-এর নয়। আসল Fiori-তে স্পেসের ট্যাব চাপলে **স্পেসের পাতা খোলে** (কোনো সারির তালিকা নয়), আর পাতাটা
 * টালির ভাগে সাজানো। ⓘ তাই এখানে: ট্যাবটা লিংক ([[spaceUrl()]]), পাতাটা মডিউলের ড্যাশবোর্ড — মাথায় মেনুর ভাগ আর টালি
 * ([[sections()]]), নিচে আগের ড্যাশবোর্ড হুবহু।
 *
 * ⛔ কেবল `tiles`-এ ([[on()]]) — অন্য রূপের একটা পিক্সেলও নড়ে না (মালিক, ১৯ নভেম্বর ২০২৬); Dynamic থাকে ১০০% Business Central।
 * ⓘ টালির উৎস বাঁয়ের মেনুর একই ডাটা ([[MenuBuilder]] — অনুমতি, "শীঘ্রই", খোলা যায় না সব সেখানেই ঠিক হয়) আর একই দলের নাম ও
 *   চিহ্ন ([[MenuLook]]) — তাই মেনু যা দেখায় না, টালিও দেখায় না।
 */
final class FioriLaunchpad
{
    public const KEY = 'tiles';

    /** খোলসের রূপ (স্কিন হলে তার গোড়া) SAP কি না — `layouts/app`-এর `$shellLook`-এর একই নিয়ম */
    public static function on(): bool
    {
        return LookRegistry::lookFor(LookPreview::orChosen(auth()->user()?->ui)) === self::KEY;
    }

    /** স্পেসের পাতার ঠিকানা — মডিউলের ড্যাশবোর্ডের রুট; SAP রূপে প্রতিটা মেনু-মডিউলের পাতা খোলে ([[ModuleDashboardController]]) */
    public static function spaceUrl(string $code): string
    {
        return route('module.dashboard', ['module' => $code]);
    }

    /**
     * মেনু থেকে একটা মডিউল (স্পেস) — ব্যবহারকারীর মেনুতে না থাকলে `null` (অনুমতি নেই, বা মডিউল বন্ধ)।
     *
     * @param  list<array<string, mixed>>  $menu
     * @return array<string, mixed>|null
     */
    public static function space(array $menu, string $code): ?array
    {
        foreach ($menu as $module) {
            if (($module['code'] ?? null) === $code || in_array($code, (array) ($module['codes'] ?? []), true)) {
                return $module;
            }
        }

        return null;
    }

    /**
     * একটা স্পেসের পাতার ভাগ আর টালি — বাঁয়ের মেনুর হুবহু একই ক্রম আর একই দল।
     *
     * ⓘ ক্রম: মডিউল `panel_sections` দিলে সেই ভাগে (সাইডবারের নিয়ম), নইলে মেনুর ক্রমে; শিরোনাম রঙিন মেনুর দলের নাম
     *   (`menu-look.php`-এর `kinds`), দল না থাকলে মেনুর ছয় ভাগের নাম। একই দলের সারি এক ভাগে (প্রতিটা ভাগ একবারই)।
     * ⓘ এই পাতার নিজের সারি (মডিউলের ড্যাশবোর্ড) টালি হয় না — পাতাটা তো ওটাই।
     *
     * @param  array<string, mixed>  $module  [[MenuBuilder::forUser()]]-এর একটা মডিউল
     * @return list<array{key: string, title: string, colour: ?string, tiles: list<array{label: string, icon: string, url: ?string, planned: bool, active: bool, badge: ?int}>}>
     */
    public static function sections(array $module): array
    {
        $code = (string) ($module['code'] ?? '');
        $rows = [];

        foreach ((array) ($module['groups'] ?? []) as $group => $items) {
            foreach ((array) $items as $item) {
                if (($item['route'] ?? null) === 'module.dashboard' && self::pointsHere($item, $code)) {
                    continue;
                }

                $rows[] = $item + ['group' => (string) $group];
            }
        }

        $panelSections = (array) ($module['panel_sections'] ?? []);

        if ($panelSections !== []) {
            $rank = array_flip($panelSections);
            $keyed = [];

            foreach ($rows as $at => $row) {
                $keyed[] = [$rank[$row['section'] ?? ''] ?? PHP_INT_MAX, $at, $row];
            }

            usort($keyed, fn (array $x, array $y): int => [$x[0], $x[1]] <=> [$y[0], $y[1]]);
            $rows = array_map(fn (array $x): array => $x[2], $keyed);
        }

        $looks = MenuLook::forRows($code, $rows);
        $sections = [];
        // ⓘ একই দলের সারি মেনুতে কয়েক জায়গায় ছড়িয়ে থাকতে পারে ("রিপোর্ট" আর "নিয়ন্ত্রণ" পালা করে) — Fiori-র পাতায় প্রতিটা ভাগ একবারই,
        //   তাই দলের প্রথম দেখার জায়গায় ভাগটা বসে আর পরের সারিগুলো সেখানেই যোগ হয় (ভাগের ভেতরে মেনুর ক্রম অটুট)
        $at = [];

        foreach ($rows as $i => $row) {
            $look = $looks[$i] ?? ['kind' => null, 'name' => null, 'colour' => null, 'icon' => null];

            if ($panelSections !== [] && ($row['section'] ?? null) !== null) {
                $key = 'section:'.$row['section'];
                $title = __($code.'::menu.section_'.$row['section']);
            } elseif ($look['kind'] !== null) {
                $key = 'kind:'.$look['kind'];
                $title = (string) $look['name'];
            } else {
                $key = 'group:'.$row['group'];
                $title = __('core.menu.'.$row['group']);
            }

            if (! isset($at[$key])) {
                $at[$key] = count($sections);
                $sections[] = ['key' => $key, 'title' => (string) $title, 'colour' => $look['colour'] ?? null, 'tiles' => []];
            }

            $icon = $look['icon']
                ?? ($look['kind'] !== null ? (MenuLook::KIND_ICONS[$look['kind']] ?? null) : null)
                ?? 'layout-grid';

            $sections[$at[$key]]['tiles'][] = [
                'label' => (string) ($row['label'] ?? ''),
                'icon' => (string) $icon,
                'url' => $row['url'] ?? null,
                'planned' => (bool) ($row['planned'] ?? false),
                'active' => (bool) ($row['active'] ?? false),
                'badge' => ($row['planned'] ?? false) ? null : (isset($row['badge']) ? (int) $row['badge'] : null),
            ];
        }

        return $sections;
    }

    /**
     * হোমের লঞ্চপ্যাড — প্রতিটা মডিউল একটা টালি (স্পেসের পাতায় যায়), রেলের ভাগে ভাগে ("অর্থ ও হিসাব", "ব্যবসা" …)।
     *
     * @param  list<array<string, mixed>>  $menu
     * @return list<array{key: string, title: string, tiles: list<array{code: string, label: string, icon: string, url: string, apps: int, active: bool}>}>
     */
    public static function home(array $menu): array
    {
        $sections = [];
        $currentKey = null;

        foreach ($menu as $module) {
            $section = (string) ($module['section'] ?? 'top');

            if ($section !== $currentKey) {
                $sections[] = [
                    'key' => $section,
                    'title' => $section === 'top' ? __('core.fiori.my_apps') : __('core.nav_section.'.$section),
                    'tiles' => [],
                ];
                $currentKey = $section;
            }

            $apps = 0;

            foreach ((array) ($module['groups'] ?? []) as $items) {
                foreach ((array) $items as $item) {
                    if (! ($item['planned'] ?? false) && ($item['url'] ?? null) !== null) {
                        $apps++;
                    }
                }
            }

            $sections[count($sections) - 1]['tiles'][] = [
                'code' => (string) $module['code'],
                'label' => (string) $module['label'],
                'icon' => (string) ($module['icon'] ?? $module['code']),
                'url' => self::spaceUrl((string) $module['code']),
                'apps' => $apps,
                'active' => false,
            ];
        }

        return $sections;
    }

    /** @param array<string, mixed> $item */
    private static function pointsHere(array $item, string $code): bool
    {
        $url = $item['url'] ?? null;

        return $url === null || $url === self::spaceUrl($code);
    }
}
