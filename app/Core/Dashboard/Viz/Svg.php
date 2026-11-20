<?php

declare(strict_types=1);

namespace App\Core\Dashboard\Viz;

/**
 * ⭐ ড্যাশবোর্ডের গ্রাফ — সার্ভারেই SVG, মালিকের অনুমোদিত নমুনার হুবহু চেহারায় (৫gynumii আর RoPEayT7, ২০ নভেম্বর ২০২৬: "ok")।
 *
 * ২২ রকম: line, bars, hbars, pairs, lollipop, donut, gauge, radial, waffle, stackedArea, stack100, funnel, heat, calendar, bullet,
 * bubble, gantt, radar, sankey, waterfall, treemap, spark। প্রতিটা একটা `viewBox="0 0 560 300"` আঁকে — কার্ডের মাপে নিজে ছোট-বড় হয়।
 *
 * ⓘ লেখা সব `e()` দিয়ে — নাম আর লেবেল মানুষের লেখা, সরাসরি SVG-তে বসে। অঙ্ক ইংরেজিতে (মালিকের নিয়ম)।
 * ⓘ রং: সারির রং [[self::PALETTE]] থেকে ক্রমে; অবস্থার রং (সবুজ/হলুদ/কমলা/লাল) কেবল অবস্থা বোঝাতে, [[self::STATUS]]।
 * ⓘ খালি তথ্য: যে গ্রাফে আঁকার মতো কিছু নেই, সে `null` দেয় — কার্ড তখন "এই সময়ে কিছু নেই" লেখে ([[x-dashboard.viz-card]])।
 */
final class Svg
{
    public const W = 560;

    public const H = 300;

    public const PALETTE = ['#2160d8', '#7c3aed', '#0ea5e9', '#10b981', '#f59e0b', '#e8175d', '#f97316', '#14b8a6', '#6366f1', '#84cc16'];

    /** ভালো → খারাপ: সবুজ, হলুদ, কমলা, লাল */
    public const STATUS = ['#16a34a', '#eab308', '#f97316', '#e11d48'];

    private const INK = '#14213d';

    private const MUTED = '#5b6b86';

    private const GRID = '#e8edf5';

    /**
     * @param  array<string, mixed>  $o
     */
    public static function render(string $kind, array $o): ?string
    {
        return match ($kind) {
            'line' => self::line($o),
            'bars' => self::bars($o),
            'hbars' => self::hbars($o),
            'pairs' => self::pairs($o),
            'lollipop' => self::lollipop($o),
            'donut' => self::donut($o),
            'gauge' => self::gauge($o),
            'radial' => self::radial($o),
            'waffle' => self::waffle($o),
            'stackedArea' => self::stackedArea($o),
            'stack100' => self::stack100($o),
            'funnel' => self::funnel($o),
            'heat' => self::heat($o),
            'calendar' => self::calendar($o),
            'bullet' => self::bullet($o),
            'bubble' => self::bubble($o),
            'gantt' => self::gantt($o),
            'radar' => self::radar($o),
            'sankey' => self::sankey($o),
            'waterfall' => self::waterfall($o),
            'treemap' => self::treemap($o),
            'spark' => self::spark($o),
            default => null,
        };
    }

    // ── ছোট সাহায্য ────────────────────────────────────────────────────

    private static function svg(string $inner, int $w = self::W, int $h = self::H): string
    {
        return '<svg viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="xMidYMid meet" role="img" focusable="false">'.$inner.'</svg>';
    }

    /**
     * @param  array{size?: float|int, fill?: string, anchor?: string, weight?: int}  $o
     */
    private static function t(float $x, float $y, string|int|float $s, array $o = []): string
    {
        return '<text x="'.self::n($x).'" y="'.self::n($y).'" font-size="'.($o['size'] ?? 13).'" fill="'.($o['fill'] ?? self::MUTED)
            .'" text-anchor="'.($o['anchor'] ?? 'middle').'" font-family="Inter, Hind Siliguri, sans-serif" font-weight="'.($o['weight'] ?? 500).'">'
            .e((string) $s).'</text>';
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @param  list<float|int>  $ys
     */
    private static function grid(float $x0, float $x1, array $ys): string
    {
        $s = '';
        foreach ($ys as $y) {
            $s .= '<line x1="'.self::n($x0).'" x2="'.self::n($x1).'" y1="'.self::n($y).'" y2="'.self::n($y).'" stroke="'.self::GRID.'"/>';
        }

        return $s;
    }

    private static function colour(array $o, int $i): string
    {
        return (string) ($o['colors'][$i % max(count($o['colors'] ?? []), 1)] ?? $o['color'] ?? self::PALETTE[$i % count(self::PALETTE)]);
    }

    /**
     * @param  list<mixed>  $v
     * @return list<float>
     */
    private static function nums(array $v): array
    {
        return array_map(fn ($x) => (float) $x, array_values($v));
    }

    private static function allZero(array $v): bool
    {
        foreach ($v as $x) {
            if ((float) $x != 0.0) {
                return false;
            }
        }

        return true;
    }

    // ── গ্রাফ ──────────────────────────────────────────────────────────

    /** কলাম — `v` মান, `l` নিচের নাম, `top` মাথার লেখা (না দিলে মানটাই), `colors`/`color` */
    private static function bars(array $o): ?string
    {
        $v = self::nums($o['v'] ?? []);
        if ($v === [] || self::allZero($v)) {
            return null;
        }
        $max = max(array_map('abs', $v)) * 1.18;
        [$x0, $x1, $y0, $y1] = [30, self::W - 8, 18, self::H - 34];
        $bw = ($x1 - $x0) / count($v);
        $s = self::grid($x0, $x1, [$y0, ($y0 + $y1) / 2, $y1]);
        foreach ($v as $i => $n) {
            $h = ($y1 - $y0) * abs($n) / $max;
            $x = $x0 + $i * $bw + $bw * .18;
            $w = $bw * .64;
            $s .= '<rect x="'.self::n($x).'" y="'.self::n($y1 - $h).'" width="'.self::n($w).'" height="'.self::n(max($h, 1)).'" rx="6" fill="'.self::colour($o, $i).'"/>';
            $s .= self::t($x + $w / 2, $y1 + 18, $o['l'][$i] ?? '', ['size' => 12.5]);
            $s .= self::t($x + $w / 2, $y1 - $h - 6, $o['top'][$i] ?? self::short($n), ['size' => 12.5, 'fill' => self::INK, 'weight' => 700]);
        }

        return self::svg($s);
    }

    /** আড়াআড়ি কলাম — নাম বাঁয়ে, মান ডানে */
    private static function hbars(array $o): ?string
    {
        $v = self::nums($o['v'] ?? []);
        if ($v === [] || self::allZero($v)) {
            return null;
        }
        $max = max($v);
        $y = 10;
        $bh = min(30, (self::H - 20) / count($v) - 10);
        $s = '';
        foreach ($v as $i => $n) {
            $w = (self::W - 200) * $n / $max;
            $s .= self::t(130, $y + $bh / 2 + 5, $o['l'][$i] ?? '', ['size' => 14, 'fill' => self::INK, 'anchor' => 'end', 'weight' => 600]);
            $s .= '<rect x="140" y="'.self::n($y).'" width="'.self::n(max($w, 2)).'" height="'.self::n($bh).'" rx="7" fill="'.self::colour($o, $i).'"/>';
            $s .= self::t(146 + $w, $y + $bh / 2 + 5, $o['top'][$i] ?? self::short($n), ['size' => 13, 'fill' => self::INK, 'anchor' => 'start', 'weight' => 700]);
            $y += $bh + 10;
        }

        return self::svg($s);
    }

    /** জোড়া কলাম — `a` (সবুজ) আর `b` (লাল) বা `ca`/`cb` রং */
    private static function pairs(array $o): ?string
    {
        $a = self::nums($o['a'] ?? []);
        $b = self::nums($o['b'] ?? []);
        if ($a === [] || (self::allZero($a) && self::allZero($b))) {
            return null;
        }
        $max = max(array_merge($a, $b)) * 1.2;
        [$x0, $x1, $y0, $y1] = [30, self::W - 8, 18, self::H - 34];
        $gw = ($x1 - $x0) / count($a);
        $s = self::grid($x0, $x1, [$y0, ($y0 + $y1) / 2, $y1]);
        foreach ($a as $i => $va) {
            $vb = $b[$i] ?? 0;
            $bw = $gw * .3;
            $xa = $x0 + $i * $gw + $gw * .17;
            $xb = $xa + $bw + 4;
            $ha = ($y1 - $y0) * $va / $max;
            $hb = ($y1 - $y0) * $vb / $max;
            $s .= '<rect x="'.self::n($xa).'" y="'.self::n($y1 - $ha).'" width="'.self::n($bw).'" height="'.self::n(max($ha, 1)).'" rx="5" fill="'.($o['ca'] ?? self::STATUS[0]).'"/>';
            $s .= self::t($xa + $bw / 2, $y1 - $ha - 6, self::short($va), ['size' => 12.5, 'fill' => self::INK, 'weight' => 700]);
            $s .= '<rect x="'.self::n($xb).'" y="'.self::n($y1 - $hb).'" width="'.self::n($bw).'" height="'.self::n(max($hb, 1)).'" rx="5" fill="'.($o['cb'] ?? self::STATUS[3]).'"/>';
            $s .= self::t($xb + $bw / 2, $y1 - $hb - 6, self::short($vb), ['size' => 12.5, 'fill' => self::INK, 'weight' => 700]);
            $s .= self::t($x0 + $i * $gw + $gw / 2, $y1 + 18, $o['l'][$i] ?? '', ['size' => 12.5]);
        }

        return self::svg($s);
    }

    /** রেখা (+ নিচে ঢাল) — `v`, ঐচ্ছিক `v2` (ড্যাশ), `l`, `min`, `color`, `c2`, `area` */
    private static function line(array $o): ?string
    {
        $v = self::nums($o['v'] ?? []);
        $v2 = self::nums($o['v2'] ?? []);
        if (count($v) < 2 || (self::allZero($v) && self::allZero($v2))) {
            return null;
        }
        $max = max(array_merge($v, $v2)) * 1.12;
        $min = (float) ($o['min'] ?? min(0.0, min(array_merge($v, $v2))));
        if ($max <= $min) {
            $max = $min + 1;
        }
        [$x0, $x1, $y0, $y1] = [30, self::W - 12, 16, self::H - 34];
        $dx = ($x1 - $x0) / (count($v) - 1);
        $y = fn (float $n) => $y1 - ($y1 - $y0) * ($n - $min) / ($max - $min);
        $c = (string) ($o['color'] ?? self::PALETTE[0]);
        $id = 'g'.substr(md5(json_encode($o)), 0, 8);
        $pts = [];
        foreach ($v as $i => $n) {
            $pts[] = self::n($x0 + $i * $dx).','.self::n($y($n));
        }
        $s = '<defs><linearGradient id="'.$id.'" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="'.$c.'" stop-opacity=".35"/><stop offset="1" stop-color="'.$c.'" stop-opacity="0"/></linearGradient></defs>';
        $s .= self::grid($x0, $x1, [$y0, ($y0 + $y1) / 2, $y1]);
        if (($o['area'] ?? true) !== false) {
            $s .= '<polygon points="'.$x0.','.$y1.' '.implode(' ', $pts).' '.$x1.','.$y1.'" fill="url(#'.$id.')"/>';
        }
        $s .= '<polyline points="'.implode(' ', $pts).'" fill="none" stroke="'.$c.'" stroke-width="3.2" stroke-linejoin="round"/>';
        if ($v2 !== []) {
            $p2 = [];
            foreach ($v2 as $i => $n) {
                $p2[] = self::n($x0 + $i * $dx).','.self::n($y($n));
            }
            $s .= '<polyline points="'.implode(' ', $p2).'" fill="none" stroke="'.($o['c2'] ?? '#f59e0b').'" stroke-width="2.6" stroke-dasharray="7 5"/>';
        }
        foreach ($pts as $i => $p) {
            [$px, $py] = explode(',', $p);
            $last = $i === count($pts) - 1;
            $s .= '<circle cx="'.$px.'" cy="'.$py.'" r="'.($last ? 6 : 3.5).'" fill="'.($last ? $c : '#fff').'" stroke="'.$c.'" stroke-width="2.4"/>';
        }
        $labels = $o['l'] ?? [];
        foreach ($labels as $i => $l) {
            $anchor = $i === 0 ? 'start' : ($i === count($labels) - 1 ? 'end' : 'middle');
            $s .= self::t($x0 + $i * $dx + ($anchor === 'start' ? -6 : ($anchor === 'end' ? 6 : 0)), $y1 + 20, (string) $l, ['size' => 12.5, 'anchor' => $anchor]);
        }

        return self::svg($s);
    }

    /** স্তূপ-এলাকা — `series` (প্রতিটা একই দৈর্ঘ্য), `colors`, `l` */
    private static function stackedArea(array $o): ?string
    {
        $series = array_map([self::class, 'nums'], $o['series'] ?? []);
        $n = count($series[0] ?? []);
        if ($n < 2) {
            return null;
        }
        $tot = [];
        for ($i = 0; $i < $n; $i++) {
            $tot[] = array_sum(array_map(fn ($s) => $s[$i] ?? 0, $series));
        }
        if (self::allZero($tot)) {
            return null;
        }
        $max = max($tot) * 1.1;
        [$x0, $x1, $y0, $y1] = [30, self::W - 12, 14, self::H - 34];
        $dx = ($x1 - $x0) / ($n - 1);
        $y = fn (float $v) => $y1 - ($y1 - $y0) * $v / $max;
        $base = array_fill(0, $n, 0.0);
        $s = self::grid($x0, $x1, [$y0, ($y0 + $y1) / 2, $y1]);
        foreach ($series as $k => $ser) {
            $top = [];
            $up = [];
            $down = [];
            for ($i = 0; $i < $n; $i++) {
                $top[$i] = $base[$i] + ($ser[$i] ?? 0);
                $up[] = self::n($x0 + $i * $dx).','.self::n($y($top[$i]));
                $down[] = self::n($x0 + $i * $dx).','.self::n($y($base[$i]));
            }
            $s .= '<polygon points="'.implode(' ', array_merge($up, array_reverse($down))).'" fill="'.self::colour($o, $k).'" fill-opacity=".85"/>';
            $base = $top;
        }
        foreach (($o['l'] ?? []) as $i => $l) {
            $s .= self::t($x0 + $i * $dx, $y1 + 20, (string) $l, ['size' => 12.5]);
        }

        return self::svg($s);
    }

    /** ডোনাট (বা `pie`) — `p` = [[নাম, মান, রং?, লেখা?]], মাঝে `c` আর `cs` */
    private static function donut(array $o): ?string
    {
        $p = array_values(array_filter($o['p'] ?? [], fn ($x) => (float) $x[1] > 0));
        $tot = array_sum(array_map(fn ($x) => (float) $x[1], $p));
        if ($tot <= 0) {
            return null;
        }
        [$cx, $cy, $r] = [150, 150, 112];
        $a0 = -M_PI / 2;
        $s = '';
        foreach ($p as $i => $part) {
            $col = $part[2] ?? self::PALETTE[$i % count(self::PALETTE)];
            $a1 = $a0 + 2 * M_PI * (float) $part[1] / $tot;
            if (count($p) === 1) {
                $s .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="none" stroke="'.$col.'" stroke-width="34"/>';
            } else {
                $big = $a1 - $a0 > M_PI ? 1 : 0;
                $g = .02;
                $s .= '<path d="M'.self::n($cx + $r * cos($a0 + $g)).' '.self::n($cy + $r * sin($a0 + $g)).' A'.$r.' '.$r.' 0 '.$big.' 1 '
                    .self::n($cx + $r * cos($a1 - $g)).' '.self::n($cy + $r * sin($a1 - $g)).'" fill="none" stroke="'.$col.'" stroke-width="34"/>';
            }
            $a0 = $a1;
        }
        $s .= self::t($cx, $cy + 6, $o['c'] ?? self::short($tot), ['size' => 30, 'fill' => self::INK, 'weight' => 800]);
        $s .= self::t($cx, $cy + 30, $o['cs'] ?? '', ['size' => 14]);
        $ly = 60;
        foreach (array_slice($p, 0, 7) as $i => $part) {
            $col = $part[2] ?? self::PALETTE[$i % count(self::PALETTE)];
            $s .= '<rect x="320" y="'.($ly - 11).'" width="13" height="13" rx="3" fill="'.$col.'"/>';
            $s .= self::t(342, $ly, (string) $part[0], ['size' => 15, 'fill' => self::INK, 'anchor' => 'start']);
            $s .= self::t(552, $ly, $part[3] ?? self::short((float) $part[1]), ['size' => 15, 'fill' => self::INK, 'anchor' => 'end', 'weight' => 700]);
            $ly += 34;
        }

        return self::svg($s);
    }

    /** কাঁটা-মিটার — `v` (0–1), `label`, `sub`, `color` (না দিলে ভালো/মাঝারি/খারাপ রং) */
    private static function gauge(array $o): ?string
    {
        if (! isset($o['v'])) {
            return null;
        }
        $v = max(0.0, min(1.0, (float) $o['v']));
        $a = M_PI * (1 - $v);
        [$cx, $cy, $r] = [280, 236, 190];
        $x = $cx + $r * cos($a);
        $y = $cy - $r * sin($a);
        $col = $o['color'] ?? ($v >= .8 ? self::STATUS[0] : ($v >= .5 ? '#f59e0b' : self::STATUS[3]));
        $s = '<path d="M90 236 A190 190 0 0 1 470 236" fill="none" stroke="'.self::GRID.'" stroke-width="34" stroke-linecap="round"/>';
        if ($v > 0.001) {
            $s .= '<path d="M90 236 A190 190 0 0 1 '.self::n($x).' '.self::n($y).'" fill="none" stroke="'.$col.'" stroke-width="34" stroke-linecap="round"/>';
        }
        $s .= self::t(280, 214, $o['label'] ?? round($v * 100).'%', ['size' => 62, 'fill' => self::INK, 'weight' => 800]);
        $s .= self::t(280, 280, $o['sub'] ?? '', ['size' => 16]);

        return self::svg($s);
    }

    /** রেডিয়াল দাগ — `p` = [[নাম, 0–1]] */
    private static function radial(array $o): ?string
    {
        $p = array_slice($o['p'] ?? [], 0, 4);
        if ($p === []) {
            return null;
        }
        [$cx, $cy] = [150, 150];
        $s = '';
        foreach ($p as $i => $part) {
            $f = max(0.0, min(.9999, (float) $part[1]));
            $r = 120 - $i * 26;
            $a = 2 * M_PI * $f;
            $big = $f > .5 ? 1 : 0;
            $col = self::PALETTE[$i];
            $s .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="none" stroke="#eef2f8" stroke-width="18"/>';
            if ($f > 0.001) {
                $s .= '<path d="M'.$cx.' '.($cy - $r).' A'.$r.' '.$r.' 0 '.$big.' 1 '.self::n($cx + $r * sin($a)).' '.self::n($cy - $r * cos($a)).'" fill="none" stroke="'.$col.'" stroke-width="18" stroke-linecap="round"/>';
            }
            $s .= '<rect x="320" y="'.(60 + $i * 40).'" width="13" height="13" rx="3" fill="'.$col.'"/>';
            $s .= self::t(342, 72 + $i * 40, (string) $part[0], ['size' => 15, 'fill' => self::INK, 'anchor' => 'start']);
            $s .= self::t(552, 72 + $i * 40, round((float) $part[1] * 100).'%', ['size' => 15, 'fill' => self::INK, 'anchor' => 'end', 'weight' => 700]);
        }

        return self::svg($s);
    }

    /** ওয়াফল — 100 ঘর, `p` = [[নাম, মান, লেখা?]]; ঘর ভাগ হয় মানের অনুপাতে */
    private static function waffle(array $o): ?string
    {
        $p = array_values(array_filter($o['p'] ?? [], fn ($x) => (float) $x[1] > 0));
        $tot = array_sum(array_map(fn ($x) => (float) $x[1], $p));
        if ($tot <= 0) {
            return null;
        }
        $cells = [];
        $acc = 0.0;
        $done = 0;
        foreach ($p as $k => $part) {
            $acc += (float) $part[1] * 100 / $tot;
            $upto = $k === count($p) - 1 ? 100 : (int) round($acc);
            for (; $done < $upto; $done++) {
                $cells[] = self::PALETTE[$k % count(self::PALETTE)];
            }
        }
        $s = '';
        [$size, $gap] = [22, 5];
        foreach ($cells as $i => $col) {
            $s .= '<rect x="'.(10 + ($i % 10) * ($size + $gap)).'" y="'.(12 + intdiv($i, 10) * ($size + $gap)).'" width="'.$size.'" height="'.$size.'" rx="5" fill="'.$col.'"/>';
        }
        $ly = 40;
        foreach ($p as $k => $part) {
            $s .= '<rect x="300" y="'.($ly - 11).'" width="13" height="13" rx="3" fill="'.self::PALETTE[$k % count(self::PALETTE)].'"/>';
            $s .= self::t(322, $ly, (string) $part[0], ['size' => 15, 'fill' => self::INK, 'anchor' => 'start']);
            $s .= self::t(552, $ly, $part[2] ?? self::short((float) $part[1]), ['size' => 15, 'fill' => self::INK, 'anchor' => 'end', 'weight' => 700]);
            $ly += 34;
        }

        return self::svg($s);
    }

    /** 100% স্তূপ-দাগ — `rows` = [[নাম, [মান…]]], `colors` */
    private static function stack100(array $o): ?string
    {
        $rows = $o['rows'] ?? [];
        if ($rows === []) {
            return null;
        }
        [$x0, $x1] = [120, self::W - 10];
        $y = 18;
        $h = min(30, (self::H - 40) / count($rows) - 14);
        $s = '';
        $any = false;
        foreach ($rows as $row) {
            $vals = self::nums($row[1]);
            $tot = array_sum($vals);
            $s .= self::t($x0 - 10, $y + $h / 2 + 5, (string) $row[0], ['size' => 14, 'fill' => self::INK, 'anchor' => 'end', 'weight' => 600]);
            if ($tot <= 0) {
                $s .= '<rect x="'.$x0.'" y="'.self::n($y).'" width="'.($x1 - $x0).'" height="'.self::n($h).'" rx="5" fill="#eef2f8"/>';
            } else {
                $any = true;
                $x = $x0;
                foreach ($vals as $i => $v) {
                    $w = ($x1 - $x0) * $v / $tot;
                    if ($w > 0) {
                        $s .= '<rect x="'.self::n($x).'" y="'.self::n($y).'" width="'.self::n(max($w - 2, 0)).'" height="'.self::n($h).'" rx="5" fill="'.self::colour($o, $i).'"/>';
                        if ($w > 44) {
                            $s .= self::t($x + $w / 2, $y + $h / 2 + 5, $o['pct'] ?? true ? round($v * 100 / $tot).'%' : self::short($v), ['size' => 12.5, 'fill' => '#fff', 'weight' => 700]);
                        }
                    }
                    $x += $w;
                }
            }
            $y += $h + 14;
        }

        return $any ? self::svg($s) : null;
    }

    /** ফানেল — `p` = [[নাম, মান, লেখা?]], প্রথমটা সবচেয়ে চওড়া */
    private static function funnel(array $o): ?string
    {
        $p = $o['p'] ?? [];
        $max = (float) max(array_map(fn ($x) => (float) $x[1], $p ?: [[0, 0]]));
        if ($max <= 0) {
            return null;
        }
        $h = (self::H - 16) / count($p);
        $s = '';
        foreach ($p as $i => $part) {
            $w = 120 + 400 * (float) $part[1] / $max;
            $x = (self::W - $w) / 2;
            $y = 8 + $i * $h;
            $s .= '<rect x="'.self::n($x).'" y="'.self::n($y).'" width="'.self::n($w).'" height="'.self::n($h - 8).'" rx="10" fill="'.self::PALETTE[$i % count(self::PALETTE)].'"/>';
            $s .= self::t(self::W / 2, $y + $h / 2 + 1, $part[0].' · '.($part[2] ?? self::short((float) $part[1])), ['size' => 15, 'fill' => '#fff', 'weight' => 700]);
        }

        return self::svg($s);
    }

    /** হিটম্যাপ — `rows`, `cols`, `v` (rows × cols), `color` */
    private static function heat(array $o): ?string
    {
        $rows = $o['rows'] ?? [];
        $cols = $o['cols'] ?? [];
        $v = $o['v'] ?? [];
        $max = 0.0;
        foreach ($v as $r) {
            foreach ($r as $x) {
                $max = max($max, (float) $x);
            }
        }
        if ($rows === [] || $cols === [] || $max <= 0) {
            return null;
        }
        $cw = (self::W - 90) / count($cols);
        $ch = (self::H - 30) / count($rows);
        $col = $o['color'] ?? '#7c3aed';
        $s = '';
        foreach ($cols as $j => $c) {
            $s .= self::t(90 + $j * $cw + $cw / 2, 16, (string) $c, ['size' => 12.5]);
        }
        foreach ($rows as $i => $r) {
            $s .= self::t(82, 24 + $i * $ch + $ch / 2 + 4, (string) $r, ['size' => 13.5, 'fill' => self::INK, 'anchor' => 'end', 'weight' => 600]);
            foreach ($cols as $j => $_) {
                $x = (float) ($v[$i][$j] ?? 0);
                $a = .12 + .88 * $x / $max;
                $s .= '<rect x="'.self::n(90 + $j * $cw + 2).'" y="'.self::n(24 + $i * $ch + 2).'" width="'.self::n($cw - 4).'" height="'.self::n($ch - 4).'" rx="6" fill="'.$col.'" fill-opacity="'.number_format($a, 2).'"/>';
                if ($cw > 40 && $ch > 22) {
                    $s .= self::t(90 + $j * $cw + $cw / 2, 24 + $i * $ch + $ch / 2 + 5, self::short($x), ['size' => 12.5, 'fill' => $a > .55 ? '#fff' : self::INK, 'weight' => 700]);
                }
            }
        }

        return self::svg($s);
    }

    /** ক্যালেন্ডার হিটম্যাপ — `days` = [[দিনের নম্বর, মান]] (35টা পর্যন্ত, শনিবার থেকে), `marks` হলে ✓/✗, `color` */
    private static function calendar(array $o): ?string
    {
        $days = $o['days'] ?? [];
        if ($days === []) {
            return null;
        }
        $max = max(1.0, (float) max(array_map(fn ($d) => (float) $d[1], $days)));
        $col = $o['color'] ?? self::STATUS[0];
        $s = '';
        foreach ((array) __('core.viz.weekdays') as $i => $d) {
            $s .= self::t(40 + $i * 78, 16, $d, ['size' => 13]);
        }
        foreach (array_slice($days, 0, 35) as $n => $d) {
            $x = 6 + ($n % 7) * 78;
            $y = 26 + intdiv($n, 7) * 53;
            $v = (float) $d[1];
            $a = .1 + .9 * $v / $max;
            $s .= '<rect x="'.$x.'" y="'.$y.'" width="70" height="46" rx="9" fill="'.$col.'" fill-opacity="'.number_format($a, 2).'"/>';
            if (! empty($o['marks'])) {
                $s .= self::t($x + 35, $y + 30, $v > 0 ? '✓' : '✗', ['size' => 20, 'fill' => $v > 0 ? '#fff' : self::STATUS[3], 'weight' => 700]);
            } else {
                $s .= self::t($x + 35, $y + 20, (string) $d[0], ['size' => 13, 'fill' => $a > .55 ? '#fff' : self::INK, 'weight' => 700]);
                if ($v > 0) {
                    $s .= self::t($x + 35, $y + 38, self::short($v), ['size' => 11.5, 'fill' => $a > .55 ? '#fff' : self::MUTED]);
                }
            }
        }

        return self::svg($s);
    }

    /** বুলেট — `p` = [[নাম, আসল 0–1, লক্ষ্য 0–1]] */
    private static function bullet(array $o): ?string
    {
        $p = $o['p'] ?? [];
        if ($p === []) {
            return null;
        }
        $y = 14;
        // ⓘ এক-দুই সারিতে দাগ 44-এর বেশি মোটা নয় — নইলে একটা সারি পুরো উচ্চতার দেয়াল হত
        $bh = min(44, (self::H - 20) / count($p) - 14);
        [$x0, $w] = [140, self::W - 160];
        $s = '';
        foreach ($p as $part) {
            $v = max(0.0, min(1.0, (float) $part[1]));
            $goal = max(0.0, min(1.0, (float) $part[2]));
            $s .= self::t(130, $y + $bh / 2 + 5, (string) $part[0], ['size' => 14, 'fill' => self::INK, 'anchor' => 'end', 'weight' => 600]);
            $s .= '<rect x="'.$x0.'" y="'.self::n($y).'" width="'.$w.'" height="'.self::n($bh).'" rx="6" fill="#eef2f8"/>';
            $s .= '<rect x="'.$x0.'" y="'.self::n($y).'" width="'.self::n($w * .75).'" height="'.self::n($bh).'" rx="6" fill="#e2e8f2"/>';
            $s .= '<rect x="'.$x0.'" y="'.self::n($y + $bh * .3).'" width="'.self::n($w * $v).'" height="'.self::n($bh * .4).'" rx="4" fill="'.($v >= $goal ? self::STATUS[0] : self::PALETTE[0]).'"/>';
            $s .= '<rect x="'.self::n($x0 + $w * $goal - 2).'" y="'.self::n($y - 3).'" width="4" height="'.self::n($bh + 6).'" fill="'.self::INK.'"/>';
            $y += $bh + 14;
        }

        return self::svg($s);
    }

    /** বাবল — `p` = [[নাম, x 0–100, y 0–100, ব্যাসার্ধ]], `x`/`y` অক্ষের নাম */
    private static function bubble(array $o): ?string
    {
        $p = $o['p'] ?? [];
        if ($p === []) {
            return null;
        }
        $s = self::grid(30, self::W - 10, [20, 140, 260]).'<line x1="30" y1="10" x2="30" y2="266" stroke="'.self::GRID.'"/>';
        foreach ($p as $i => $b) {
            $cx = 40 + max(0, min(100, (float) $b[1])) * 4.9;
            $cy = 262 - max(0, min(100, (float) $b[2])) * 2.4;
            $r = max(6.0, min(40.0, (float) $b[3]));
            $cy = max($r + 4, min(266 - $r, $cy));
            $cx = max(30 + $r, min(self::W - 10 - $r, $cx));
            $s .= '<circle cx="'.self::n($cx).'" cy="'.self::n($cy).'" r="'.self::n($r).'" fill="'.self::PALETTE[$i % count(self::PALETTE)].'" fill-opacity=".72" stroke="#fff" stroke-width="2"/>';
            // ⓘ নাম বৃত্তে ধরলে ভেতরে সাদায়; না ধরলে বৃত্তের নিচে কালিতে — লম্বা নাম (IP ঠিকানা) ছোট বৃত্তের বাইরে ছড়ায় না
            $label = (string) $b[0];
            if (mb_strlen($label) * 6.5 <= $r * 2 - 4) {
                $s .= self::t($cx, $cy + 4, $label, ['size' => 12, 'fill' => '#fff', 'weight' => 700]);
            } else {
                $s .= self::t($cx, min($cy + $r + 14, 278), $label, ['size' => 11.5, 'fill' => self::INK, 'weight' => 600]);
            }
        }
        $s .= self::t(self::W - 10, 290, $o['x'] ?? '', ['size' => 12.5, 'anchor' => 'end']).self::t(34, 12, $o['y'] ?? '', ['size' => 12.5, 'anchor' => 'start']);

        return self::svg($s);
    }

    /** লোলিপপ — `v`, `l`, `top` */
    private static function lollipop(array $o): ?string
    {
        $v = self::nums($o['v'] ?? []);
        if ($v === [] || self::allZero($v)) {
            return null;
        }
        $max = max($v);
        $y = 16;
        $gap = (self::H - 24) / count($v);
        $s = '';
        foreach ($v as $i => $n) {
            $x = 150 + (self::W - 280) * $n / $max;
            $s .= self::t(140, $y + 5, $o['l'][$i] ?? '', ['size' => 14, 'fill' => self::INK, 'anchor' => 'end', 'weight' => 600]);
            $s .= '<line x1="150" x2="'.self::n($x).'" y1="'.self::n($y).'" y2="'.self::n($y).'" stroke="#cbd5e6" stroke-width="3"/>';
            $s .= '<circle cx="'.self::n($x).'" cy="'.self::n($y).'" r="10" fill="'.self::PALETTE[$i % count(self::PALETTE)].'"/>';
            $s .= self::t($x + 16, $y + 5, $o['top'][$i] ?? self::short($n), ['size' => 13, 'fill' => self::INK, 'anchor' => 'start', 'weight' => 700]);
            $y += $gap;
        }

        return self::svg($s);
    }

    /** সময়রেখা — `ticks`, `p` = [[নাম, শুরু 0–1, শেষ 0–1, রং?]], `today` 0–1 */
    private static function gantt(array $o): ?string
    {
        $p = array_slice($o['p'] ?? [], 0, 6);
        $ticks = $o['ticks'] ?? [];
        if ($p === []) {
            return null;
        }
        $s = '';
        foreach ($ticks as $i => $tk) {
            $x = 150 + $i * (self::W - 160) / max(count($ticks) - 1, 1);
            $s .= '<line x1="'.self::n($x).'" x2="'.self::n($x).'" y1="14" y2="'.(self::H - 10).'" stroke="#eef2f8"/>'.self::t($x, 12, (string) $tk, ['size' => 12]);
        }
        $y = 22;
        // ⓘ সারি যত কমই হোক, দাগ 40-এর বেশি মোটা নয় — একটা সারি পুরো উচ্চতা জুড়ে দেয়াল হত
        $bh = min(40, (self::H - 40) / count($p) - 10);
        foreach ($p as $i => $bar) {
            $a = max(0.0, min(1.0, (float) $bar[1]));
            $b = max($a + .01, min(1.0, (float) $bar[2]));
            $s .= self::t(140, $y + $bh / 2 + 5, (string) $bar[0], ['size' => 13.5, 'fill' => self::INK, 'anchor' => 'end', 'weight' => 600]);
            // ⓘ পঞ্চম ঘরে পুরো নাম (ছোট করা নামের) — দাগের উপর রাখলে দেখা যায়, পর্দা-পাঠকও পড়ে; কিছু হারায় না
            $full = isset($bar[4]) && $bar[4] !== '' ? '<title>'.e((string) $bar[4]).'</title>' : '';
            $s .= '<rect x="'.self::n(150 + (self::W - 160) * $a).'" y="'.self::n($y).'" width="'.self::n((self::W - 160) * ($b - $a)).'" height="'.self::n($bh).'" rx="8" fill="'.($bar[3] ?? self::PALETTE[$i % count(self::PALETTE)]).'">'.$full.'</rect>';
            $y += $bh + 10;
        }
        $today = 150 + (self::W - 160) * (float) ($o['today'] ?? 0);
        $s .= '<line x1="'.self::n($today).'" x2="'.self::n($today).'" y1="14" y2="'.(self::H - 6).'" stroke="'.self::STATUS[3].'" stroke-width="2" stroke-dasharray="5 4"/>';
        $s .= self::t($today, self::H - 1, __('core.viz.today'), ['size' => 12, 'fill' => self::STATUS[3], 'weight' => 700]);

        return self::svg($s);
    }

    /** রাডার — `axes`, `sets` = [[0–1 …], …] */
    private static function radar(array $o): ?string
    {
        $axes = $o['axes'] ?? [];
        $sets = $o['sets'] ?? [];
        $n = count($axes);
        if ($n < 3 || $sets === []) {
            return null;
        }
        [$cx, $cy, $R] = [280, 150, 118];
        $pt = fn (int $i, float $k) => self::n($cx + $R * $k * cos(-M_PI / 2 + 2 * M_PI * $i / $n)).','.self::n($cy + $R * $k * sin(-M_PI / 2 + 2 * M_PI * $i / $n));
        $s = '';
        foreach ([1, .66, .33] as $k) {
            $s .= '<polygon points="'.implode(' ', array_map(fn ($i) => $pt($i, $k), range(0, $n - 1))).'" fill="none" stroke="#e5eaf2"/>';
        }
        foreach ($sets as $k => $set) {
            $col = self::PALETTE[$k % count(self::PALETTE)];
            $s .= '<polygon points="'.implode(' ', array_map(fn ($i) => $pt($i, max(0.0, min(1.0, (float) ($set[$i] ?? 0)))), range(0, $n - 1))).'" fill="'.$col.'" fill-opacity=".25" stroke="'.$col.'" stroke-width="2.5"/>';
        }
        foreach ($axes as $i => $ax) {
            $a = -M_PI / 2 + 2 * M_PI * $i / $n;
            $s .= self::t($cx + ($R + 24) * cos($a), $cy + ($R + 24) * sin($a) + 4, (string) $ax, ['size' => 13, 'fill' => self::INK]);
        }

        return self::svg($s);
    }

    /** প্রবাহ — `left` = [[নাম, মান, ডানের নাম]], `right` = [[নাম, মান]] */
    private static function sankey(array $o): ?string
    {
        $left = array_values(array_filter($o['left'] ?? [], fn ($x) => (float) $x[1] > 0));
        $right = array_values(array_filter($o['right'] ?? [], fn ($x) => (float) $x[1] > 0));
        $tot = array_sum(array_map(fn ($x) => (float) $x[1], $left));
        if ($tot <= 0 || $right === []) {
            return null;
        }
        $sc = (self::H - 20 - (count($left) - 1) * 8) / $tot;
        $ys = [];
        $yr = 10;
        foreach ($right as $r) {
            $ys[$r[0]] = ['y' => $yr, 'h' => (float) $r[1] * $sc];
            $yr += (float) $r[1] * $sc + 8;
        }
        $s = '';
        $y = 10;
        $used = [];
        foreach ($left as $i => $l) {
            $h = (float) $l[1] * $sc;
            $to = $ys[$l[2]] ?? null;
            if ($to === null) {
                continue;
            }
            $off = $used[$l[2]] ?? 0;
            $col = self::PALETTE[$i % count(self::PALETTE)];
            $s .= '<path d="M150 '.self::n($y + $h / 2).' C 280 '.self::n($y + $h / 2).', 300 '.self::n($to['y'] + $off + $h / 2).', 410 '.self::n($to['y'] + $off + $h / 2).'" stroke="'.$col.'" stroke-opacity=".45" stroke-width="'.self::n(max($h, 2)).'" fill="none"/>';
            $s .= '<rect x="138" y="'.self::n($y).'" width="12" height="'.self::n(max($h, 2)).'" rx="3" fill="'.$col.'"/>'.self::t(130, $y + $h / 2 + 5, (string) $l[0], ['size' => 13.5, 'fill' => self::INK, 'anchor' => 'end', 'weight' => 600]);
            $used[$l[2]] = $off + $h;
            $y += $h + 8;
        }
        foreach ($right as $r) {
            $s .= '<rect x="410" y="'.self::n($ys[$r[0]]['y']).'" width="12" height="'.self::n(max($ys[$r[0]]['h'], 2)).'" rx="3" fill="'.self::INK.'"/>'
                .self::t(430, $ys[$r[0]]['y'] + $ys[$r[0]]['h'] / 2 + 5, $r[0].' · '.self::short((float) $r[1]), ['size' => 13.5, 'fill' => self::INK, 'anchor' => 'start', 'weight' => 600]);
        }

        return self::svg($s);
    }

    /** সিঁড়ি — `steps` = [[নাম, মান, 'total'|'', রং?, মাথার লেখা?]] */
    private static function waterfall(array $o): ?string
    {
        $steps = $o['steps'] ?? [];
        if ($steps === []) {
            return null;
        }
        $run = 0.0;
        $max = 0.0;
        foreach ($steps as $st) {
            $run = ($st[2] ?? '') === 'total' ? (float) $st[1] : $run + (float) $st[1];
            $max = max($max, abs($run), abs((float) $st[1]));
        }
        if ($max <= 0) {
            return null;
        }
        $max *= 1.12;
        [$x0, $x1, $y0, $y1] = [16, self::W - 8, 26, self::H - 48];
        $bw = ($x1 - $x0) / count($steps);
        $y = fn (float $v) => $y1 - ($y1 - $y0) * $v / $max;
        $run = 0.0;
        $s = '';
        foreach ($steps as $i => $st) {
            $x = $x0 + $i * $bw + $bw * .14;
            $w = $bw * .72;
            if (($st[2] ?? '') === 'total') {
                [$a, $b] = [0.0, (float) $st[1]];
                $run = (float) $st[1];
                $c = $st[3] ?? '#5b6b86';
            } else {
                $a = $run;
                $run += (float) $st[1];
                $b = $run;
                $c = $st[3] ?? ((float) $st[1] >= 0 ? self::STATUS[0] : self::STATUS[3]);
            }
            $top = $y(max($a, $b));
            $hh = abs($y($a) - $y($b));
            $s .= '<rect x="'.self::n($x).'" y="'.self::n($top).'" width="'.self::n($w).'" height="'.self::n(max($hh, 2)).'" rx="5" fill="'.$c.'"/>';
            $s .= self::t($x + $w / 2, $top - 7, $st[4] ?? self::short((float) $st[1]), ['size' => 12.5, 'fill' => self::INK, 'weight' => 700]);
            $s .= self::t($x + $w / 2, $y1 + 18, (string) $st[0], ['size' => 12.5]);
        }

        return self::svg($s);
    }

    /** ট্রিম্যাপ — `items` = [[নাম, মান, রং?, লেখা?]], বড় থেকে ছোট */
    private static function treemap(array $o): ?string
    {
        $items = array_values(array_filter($o['items'] ?? [], fn ($x) => (float) $x[1] > 0));
        usort($items, fn ($a, $b) => (float) $b[1] <=> (float) $a[1]);
        if ($items === []) {
            return null;
        }
        [$x, $y, $w, $h] = [0.0, 0.0, (float) self::W, (float) self::H];
        $horiz = true;
        $s = '';
        foreach ($items as $i => $it) {
            $rest = array_sum(array_map(fn ($z) => (float) $z[1], array_slice($items, $i)));
            $share = (float) $it[1] / $rest;
            if ($i === count($items) - 1) {
                [$rw, $rh] = [$w, $h];
            } elseif ($horiz) {
                [$rw, $rh] = [$w * $share, $h];
            } else {
                [$rw, $rh] = [$w, $h * $share];
            }
            $col = $it[2] ?? self::PALETTE[$i % count(self::PALETTE)];
            $s .= '<rect x="'.self::n($x + 2).'" y="'.self::n($y + 2).'" width="'.self::n(max($rw - 4, 0)).'" height="'.self::n(max($rh - 4, 0)).'" rx="8" fill="'.$col.'"/>';
            if ($rw > 70 && $rh > 40) {
                $s .= self::t($x + 12, $y + 26, (string) $it[0], ['size' => 15, 'fill' => '#fff', 'anchor' => 'start', 'weight' => 700]);
                $s .= self::t($x + 12, $y + 46, $it[3] ?? self::short((float) $it[1]), ['size' => 13, 'fill' => '#fff', 'anchor' => 'start']);
            } elseif ($rw > 24 && $rh > 24) {
                // ⓘ সরু বাক্স — ছোট অক্ষরে নামের প্রথম অংশ, যাতে কোনো রং নামহীন না থাকে
                $s .= self::t($x + $rw / 2, $y + $rh / 2 + 4, mb_substr((string) $it[0], 0, max(2, (int) ($rw / 9))), ['size' => 11, 'fill' => '#fff', 'weight' => 700]);
            }
            if ($horiz) {
                $x += $rw;
                $w -= $rw;
            } else {
                $y += $rh;
                $h -= $rh;
            }
            $horiz = ! $horiz;
        }

        return self::svg($s);
    }

    /** ছোট ঘরের রেখা — `p` = [[নাম, বড় লেখা, [মান…]]] (চারটা পর্যন্ত, 2×2) */
    private static function spark(array $o): ?string
    {
        $p = array_slice($o['p'] ?? [], 0, 4);
        if ($p === []) {
            return null;
        }
        [$cw, $ch] = [self::W / 2, self::H / 2];
        $s = '';
        foreach ($p as $i => $part) {
            $x = ($i % 2) * $cw;
            $y = intdiv($i, 2) * $ch;
            $vals = self::nums($part[2] ?? []);
            $s .= '<rect x="'.self::n($x + 4).'" y="'.self::n($y + 4).'" width="'.self::n($cw - 8).'" height="'.self::n($ch - 8).'" rx="12" fill="#f6f8fc"/>';
            $s .= self::t($x + 16, $y + 28, (string) $part[0], ['size' => 14, 'anchor' => 'start']).self::t($x + 16, $y + 58, (string) $part[1], ['size' => 26, 'fill' => self::INK, 'anchor' => 'start', 'weight' => 800]);
            if (count($vals) > 1) {
                [$max, $min] = [max($vals), min($vals)];
                $pts = [];
                foreach ($vals as $k => $v) {
                    $pts[] = self::n($x + 14 + $k * ($cw - 40) / (count($vals) - 1)).','.self::n($y + $ch - 18 - ($ch - 80) * ($v - $min) / (($max - $min) ?: 1));
                }
                $s .= '<polyline points="'.implode(' ', $pts).'" fill="none" stroke="'.self::PALETTE[$i].'" stroke-width="2.6"/>';
            }
        }

        return self::svg($s);
    }

    /** ছোট লেখা — 12500 → 12.5k, 1250000 → 12.5L, 12500000 → 1.25Cr; ছোট সংখ্যা যেমন আছে (ইংরেজি অঙ্ক) */
    public static function short(float|int $n): string
    {
        $a = abs((float) $n);
        $sign = $n < 0 ? '−' : '';

        return match (true) {
            $a >= 10000000 => $sign.self::trim($a / 10000000).' কো',
            $a >= 100000 => $sign.self::trim($a / 100000).' লা',
            $a >= 1000 => $sign.self::trim($a / 1000).'k',
            default => $sign.self::trim($a),
        };
    }

    private static function trim(float $v): string
    {
        $s = number_format($v, $v < 10 ? 2 : 1, '.', '');

        return rtrim(rtrim($s, '0'), '.');
    }
}
