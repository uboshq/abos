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

    /** @var array{kinds?: array<string, array{0: string, 1: string}>, modules?: array<string, array<string, array{0: string, 1: string}>>}|null */
    private static ?array $data = null;

    /** @return array{kinds: array<string, array{0: string, 1: string}>, modules: array<string, array<string, array{0: string, 1: string}>>} */
    public static function data(): array
    {
        return self::$data ??= (array) require resource_path('menu-look.php');
    }

    /**
     * এক মডিউলের সারিগুলোর রূপ — প্রতিটা সারি: দলের ধরন, দলের নাম, রং, আইকন, আর এখানে নতুন দল শুরু কি না।
     *
     * @param  list<array<string, mixed>>  $rows  পর্দার ক্রমে, প্রতিটায় `key`
     * @return list<array{kind: string|null, name: string|null, colour: string|null, icon: string|null, starts: bool}>
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
                $out[] = ['kind' => null, 'name' => null, 'colour' => null, 'icon' => null, 'starts' => false];
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
                'starts' => $starts,
            ];
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
