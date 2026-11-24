<?php

declare(strict_types=1);

namespace App\Core\Navigation;

/**
 * ⭐ রঙিন মডিউল-মেনু — মালিক, ৮ নভেম্বর ২০২৬: "মডিউল মেনু বার … গ্রুপ ওয়াইজ বিভিন্ন কালার … পাশে ছোট ছোট আইকন" · "একই আইকন দুইটাই
 * বসবে না, প্রত্যেকটার জন্য ইউনিক আইকন" · "লালের পাশে মেরুন দিলে লালই দেখা যায় … পাশাপাশি একই রকমের কালার দিবা না"।
 * নমুনা "রঙিন মডিউল মেনু" ("ok")।
 *
 * ⓘ প্রতিটা মেনু-সারির (অনুবাদের চাবি ধরে) দল আর নিজের আইকন আসে `resources/menu-look.php` থেকে; রং দলের ধরন ধরে, আর পাশাপাশি দুই
 * দলের রং রং-চাকায় অন্তত [[APART]]° দূরে — কাছাকাছি হলে প্যালেটের দূরতমটা। ⓘ তালিকায় নেই এমন সারি আগের মতো (রং বা আইকন ছাড়া)।
 */
final class MenuLook
{
    /** পাশাপাশি দুই দলের রঙের ন্যূনতম দূরত্ব (রং-চাকার ডিগ্রি) */
    public const APART = 60;

    /** রং-চাকায় দূরে দূরে বারোটা রং — লাল, নীল, সোনালি, সবুজ, বেগুনি, কমলা, ফিরোজা, গোলাপি… */
    public const PALETTE = ['#dc2626', '#2563eb', '#ca8a04', '#16a34a', '#9333ea', '#ea580c', '#0891b2', '#db2777', '#65a30d', '#4f46e5', '#0d9488', '#b45309'];

    /** "শুরু" দলের নিরপেক্ষ রং */
    public const NEUTRAL = '#475569';

    /** @var array{kinds?: array<string, array{0: string, 1: string, 2?: string}>, order?: array<string, list<string>>, modules?: array<string, array<string, array{0: string, 1: string}>>}|null */
    private static ?array $data = null;

    /** ⓘ SAP আর Dynamic রূপের তালিকা — মডিউল এক করার আগের ([[Ui::keepsModulesApart()]]) */
    private static ?array $apartData = null;

    /**
     * ⛔ SAP আর Dynamic রূপে এক করার আগের তালিকা (`menu-look-unmerged.php`) — মালিক, ২০ নভেম্বর ২০২৬: ওই দুই রূপ ১০০% আগের মতো।
     *
     * @return array{kinds: array<string, array{0: string, 1: string, 2?: string}>, order?: array<string, list<string>>, modules: array<string, array<string, array{0: string, 1: string}>>}
     */
    public static function data(): array
    {
        if (\App\Core\Support\Ui::viewerKeepsModulesApart()) {
            return self::$apartData ??= (array) require resource_path('menu-look-unmerged.php');
        }

        return self::$data ??= (array) require resource_path('menu-look.php');
    }

    /**
     * ⭐ দলের নিজের চিহ্ন — ভাঁজ-করা দলের মাথার বোতামে (মালিক, ২০ নভেম্বর ২০২৬: "দলের মাথা আইকন + নাম, তীর নয়")।
     * ⓘ তালিকার তৃতীয় ঘর; না থাকলে উপরের বারের [[KIND_ICONS]]; তাও না থাকলে null।
     */
    public static function kindIcon(?string $kind): ?string
    {
        if ($kind === null) {
            return null;
        }

        return self::data()['kinds'][$kind][2] ?? self::KIND_ICONS[$kind] ?? null;
    }

    /**
     * ⭐ মডিউলের সারিগুলো তার দলের ক্রমে — `menu-look.php`-এর `order` (মালিক, ২০ নভেম্বর ২০২৬: এক করা মডিউল — হিসাব ও অর্থ,
     * বিক্রয়, ক্রয়)। ⓘ তালিকায় দলের নাম থাকলে সেই দলের সারিগুলো ঐ জায়গায়, দলের ভিতরে `modules`-এর লেখা ক্রমে; `*` মানে
     * "তালিকায় নাম নেই এমন সব দল", তাদের আগের ক্রমেই। ⓘ মডিউলের `order` না থাকলে কিছুই নড়ে না — আগের মতো।
     *
     * ⓘ `$groupKinds` — একই দলের ছড়ানো সারিগুলো দলের প্রথম জায়গায় একসাথে (ভাঁজ-করা মেনুতে এক দলের একটাই মাথা; বিক্রয়ে
     *   "রিপোর্ট" আর "নিয়ন্ত্রণ" পাঁচবার করে আসত)। কেবল ভাঁজের তিন রূপ চায় — বাকি রূপে সারির ক্রম আগের মতো।
     *
     * @param  list<array<string, mixed>>  $rows  পর্দার ক্রমে, প্রতিটায় `key`
     * @return list<array<string, mixed>>
     */
    public static function arrange(string $module, array $rows, bool $groupKinds = false): array
    {
        $rows = self::ordered($module, array_values($rows));

        if (! $groupKinds) {
            return $rows;
        }

        $catalog = self::data()['modules'][$module] ?? [];
        $first = [];
        $keyed = [];
        foreach ($rows as $at => $row) {
            $kind = $catalog[(string) ($row['key'] ?? '')][0] ?? null;
            // ⓘ দল-ছাড়া সারি নিজের জায়গাতেই
            $bucket = $kind === null ? 'row:'.$at : 'kind:'.$kind;
            $first[$bucket] ??= $at;
            $keyed[] = [$first[$bucket], $at, $row];
        }

        usort($keyed, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn (array $k): array => $k[2], $keyed);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function ordered(string $module, array $rows): array
    {
        $order = self::data()['order'][$module] ?? null;

        if (! is_array($order) || $order === []) {
            return $rows;
        }

        $catalog = self::data()['modules'][$module] ?? [];
        $catalogAt = array_flip(array_keys($catalog));
        $rank = array_flip($order);
        $rest = $rank['*'] ?? count($order);

        $keyed = [];
        foreach (array_values($rows) as $at => $row) {
            $key = (string) ($row['key'] ?? '');
            $kind = $catalog[$key][0] ?? null;
            $listed = $kind !== null && isset($rank[$kind]);

            $keyed[] = [
                $listed ? $rank[$kind] : $rest,
                // ⓘ তালিকায় নাম থাকা দলে লেখা ক্রম; `*`-এর দলগুলোয় আগের ক্রম
                $listed ? ($catalogAt[$key] ?? PHP_INT_MAX) : $at,
                $at,
                $row,
            ];
        }

        usort($keyed, fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(fn (array $k): array => $k[3], $keyed);
    }

    /**
     * ভাগে সাজানো মেনুর ভাগের ভিতরের ক্রম — তালিকার (`modules`) লেখা ক্রম; নেই এমন সারি শেষে, আগের ক্রমে।
     *
     * @return array<string, int>
     */
    public static function rowOrder(string $module): array
    {
        return array_flip(array_keys(self::data()['modules'][$module] ?? []));
    }

    /**
     * ⭐ ভাগের মাথার টাইল — রং আর চিহ্ন (`menu-look.php`-এর `sections`); তালিকায় না থাকা ভাগ নিরপেক্ষ রঙে, যাতে টাইল ছাড়া মাথা না থাকে।
     *
     * @return array{colour: string, icon: string}
     */
    public static function section(?string $section): array
    {
        $entry = self::data()['sections'][$section ?? ''] ?? null;

        return ['colour' => $entry[0] ?? self::NEUTRAL, 'icon' => $entry[1] ?? 'folder-open'];
    }

    /**
     * এক মডিউলের সারিগুলোর রূপ — প্রতিটা সারি: দলের ধরন, দলের নাম, রং, আইকন, আর এখানে নতুন দল শুরু কি না।
     *
     * @param  list<array<string, mixed>>  $rows  পর্দার ক্রমে, প্রতিটায় `key`
     * @return list<array{kind: string|null, name: string|null, colour: string|null, icon: string|null, kind_icon: string|null, starts: bool}>
     */
    public static function forRows(string $module, array $rows): array
    {
        $catalog = self::data()['modules'][$module] ?? [];
        $kinds = self::data()['kinds'] ?? [];
        $out = [];
        $chosen = [];
        $byKind = [];
        $previousKind = null;

        foreach ($rows as $row) {
            $entry = $catalog[(string) ($row['key'] ?? '')] ?? null;

            if ($entry === null) {
                $out[] = ['kind' => null, 'name' => null, 'colour' => null, 'icon' => null, 'kind_icon' => null, 'starts' => false];
                $previousKind = null;

                continue;
            }

            [$kind, $icon] = $entry;
            $starts = $kind !== $previousKind;

            if ($starts) {
                $want = $kind === 'start' ? self::NEUTRAL : ($byKind[$kind] ?? ($kinds[$kind][1] ?? self::PALETTE[0]));
                $prev = $chosen[count($chosen) - 1] ?? null;
                $prev2 = $chosen[count($chosen) - 2] ?? null;

                if ($prev !== null && self::apart($want, $prev) < self::APART) {
                    $want = self::farthest($prev, $prev2, $chosen);
                }

                $byKind[$kind] ??= $want;
                $chosen[] = $want;
            }

            $out[] = [
                'kind' => $kind,
                'name' => __('core.menu_kind.'.$kind),
                'colour' => $chosen[count($chosen) - 1],
                'icon' => $icon,
                'kind_icon' => self::kindIcon($kind),
                'starts' => $starts,
            ];
            $previousKind = $kind;
        }

        return $out;
    }

    /**
     * ⭐ দলের নিজের চিহ্ন — মেনু লুকালে উপরের বারে দলের ঘরে (মালিক, ৯ নভেম্বর ২০২৬: নমুনা "মেনু লুকালে উপরে দলের বার"-এ "ok —
     * eta korai ache sudu color kora baki")। ⓘ তালিকায় না থাকলে দলের প্রথম সারির চিহ্ন।
     */
    /**
     * ⭐ রেলে যে মডিউলের টাইলে স্থির ছবির বদলে জীবন্ত চিহ্ন — মডিউলের কোড => চিহ্ন (মালিক, ১৩ নভেম্বর ২০২৬: "কোপাইলটের … লাইভ আইকন")।
     * ⓘ রেল নিজে কোনো মডিউলের নাম জানে না; কেবল এই তালিকা দেখে।
     *
     * @var array<string, string>
     */
    public const LIVING_TILES = ['assistant' => 'copilot-mark'];

    public const KIND_ICONS = [
        'start' => 'house', 'master' => 'library', 'papers' => 'files', 'books' => 'notebook-tabs', 'reports' => 'chart-column',
        'ledger' => 'book-marked', 'control' => 'shield-alert', 'fin' => 'scale', 'close' => 'calendar-cog', 'crm' => 'users-round',
        'quote' => 'file-pen', 'orders' => 'clipboard-list', 'direct' => 'store', 'deliver' => 'truck', 'bill' => 'receipt-text',
        'returns' => 'rotate-ccw', 'price' => 'tags', 'target' => 'goal', 'setup' => 'settings', 'notice' => 'megaphone',
    ];

    /**
     * ⭐ উপরের বারের ঘরগুলোর রং — প্রতিটা ঘর তার দলের রঙে, আর পাশাপাশি দুই আলাদা দলের রং অন্তত [[APART]]° দূরে (সাইডবারের
     * একই নিয়ম, [[forRows()]]); একই দলের পরপর ঘর একই রঙে। দল না জানা ঘর (`null`) রং পায় না।
     *
     * @param  list<string|null>  $kinds  ঘরগুলোর দল, বারের ক্রমে
     * @return list<string|null>
     */
    public static function forBar(array $kinds): array
    {
        $catalog = self::data()['kinds'] ?? [];
        $out = [];
        $chosen = [];
        $byKind = [];
        $previousKind = null;

        foreach ($kinds as $kind) {
            if ($kind === null) {
                $out[] = null;
                $previousKind = null;

                continue;
            }

            if ($kind === $previousKind) {
                $out[] = $chosen[count($chosen) - 1];

                continue;
            }

            $want = $byKind[$kind] ?? ($kind === 'start' ? self::NEUTRAL : ($catalog[$kind][1] ?? self::PALETTE[0]));
            $prev = $chosen[count($chosen) - 1] ?? null;

            if ($prev !== null && self::apart($want, $prev) < self::APART) {
                $want = self::farthest($prev, $chosen[count($chosen) - 2] ?? null, $chosen);
            }

            $byKind[$kind] ??= $want;
            $chosen[] = $want;
            $out[] = $want;
            $previousKind = $kind;
        }

        return $out;
    }

    /** দুই রঙের রং-চাকার দূরত্ব (ডিগ্রি) — একটা ধূসর হলে অনেক দূরে, দুইটাই ধূসর হলে শূন্য */
    public static function apart(string $a, string $b): float
    {
        [$ha, $sa] = self::hue($a);
        [$hb, $sb] = self::hue($b);

        if ($sa < 0.2 || $sb < 0.2) {
            return ($sa < 0.2) !== ($sb < 0.2) ? 180.0 : 0.0;
        }

        $d = fmod(abs($ha - $hb), 360.0);

        return min($d, 360.0 - $d);
    }

    /** @param list<string> $used */
    private static function farthest(string $prev, ?string $prev2, array $used): string
    {
        $best = self::PALETTE[0];
        $score = -INF;

        foreach (self::PALETTE as $colour) {
            $s = min(self::apart($colour, $prev), $prev2 === null ? 360.0 : self::apart($colour, $prev2)) - (in_array($colour, $used, true) ? 5 : 0);

            if ($s > $score) {
                $score = $s;
                $best = $colour;
            }
        }

        return $best;
    }

    /** @return array{0: float, 1: float} রং-চাকার কোণ আর গাঢ়ত্ব (HLS-এর S) */
    private static function hue(string $hex): array
    {
        $r = hexdec(substr($hex, 1, 2)) / 255;
        $g = hexdec(substr($hex, 3, 2)) / 255;
        $b = hexdec(substr($hex, 5, 2)) / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d == 0.0) {
            return [0.0, 0.0];
        }

        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => fmod((($g - $b) / $d) + ($g < $b ? 6 : 0), 6),
            $g => (($b - $r) / $d) + 2,
            default => (($r - $g) / $d) + 4,
        };

        return [$h * 60, $s];
    }
}
